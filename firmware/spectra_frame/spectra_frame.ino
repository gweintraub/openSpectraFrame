#include <FS.h>
#include "driver.h"
#include <TFT_eSPI.h>
#include <PNGdec.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <time.h>
#include <Preferences.h>  // For permanent flash storage
#include "secrets.h"

#if !defined(BOARD_HAS_PSRAM)
#error "FATAL: PSRAM is not enabled! Go to Tools > PSRAM and select 'Enabled' before compiling."
#endif

EPaper epaper;
PNG png;

const bool DEV_MODE = true;

// --- VARIABLES THAT SURVIVE DEEP SLEEP ---
RTC_DATA_ATTR int retryCount = 0;
RTC_DATA_ATTR int frame_state = 0;
RTC_DATA_ATTR float last_voltage = 0.0;
RTC_DATA_ATTR uint32_t next_midnight_epoch = 0;  // Tracks the next daily update target
RTC_DATA_ATTR float peak_charge_voltage = 0.0;
RTC_DATA_ATTR int network_ping_counter = 0;
RTC_DATA_ATTR bool is_gift_transit = false;  // Tracks if are in fast-pulse gift mode

String current_timezone = "EST5EDT,M3.2.0,M11.1.0";  // Default, will be overwritten

// ----------------------------------------------------
// ORIGINAL, FLAWLESS COLOR RENDERING METHOD
// ----------------------------------------------------
int pngDraw(PNGDRAW* pDraw) {
  if (pDraw->y == 0) {
    Serial.println("SUCCESS: Drawing high-res image with exact hex-color snapping!");
  }

  static uint16_t lineBuffer[1600];
  png.getLineAsRGB565(pDraw, lineBuffer, PNG_RGB565_BIG_ENDIAN, 0xffffffff);

  for (int x = 0; x < pDraw->iWidth; x++) {
    uint16_t color = lineBuffer[x];
    uint16_t finalColor = 0x0000;  // Default to Seeed's TFT_WHITE

    switch (color) {
      // --- PURE BLACK & WHITE ---
      case 0x0000: finalColor = 0x000F; break;  // Black
      case 0xFFFF:
        finalColor = 0x0000;
        break;  // White

      // --- BIG ENDIAN MATCHES (Realistic Colors) ---
      case 0xC904: finalColor = 0x0006; break;  // Realistic Red
      case 0x03C5: finalColor = 0x0002; break;  // Realistic Green
      case 0x01D5: finalColor = 0x000D; break;  // Realistic Blue
      case 0xE5C1:
        finalColor = 0x000B;
        break;  // Realistic Yellow

      // --- LITTLE ENDIAN MATCHES (Byte Swapped) ---
      case 0x04C9: finalColor = 0x0006; break;  // Realistic Red
      case 0xC503: finalColor = 0x0002; break;  // Realistic Green
      case 0xD501: finalColor = 0x000D; break;  // Realistic Blue
      case 0xC1E5: finalColor = 0x000B; break;  // Realistic Yellow

      default:
        finalColor = (color > 0x7BEF) ? 0x0000 : 0x000F;
        break;
    }
    epaper.drawPixel(x, pDraw->y, finalColor);
  }
  yield();
  return 1;
}

const int BATTERY_ADC_PIN = A0;
const int BATTERY_EN_PIN = D5;

float readBatteryVoltage() {
  pinMode(BATTERY_EN_PIN, OUTPUT);
  digitalWrite(BATTERY_EN_PIN, HIGH);
  delay(10);

  analogReadResolution(12);

  int totalAdc = 0;
  for (int i = 0; i < 20; i++) {
    totalAdc += analogRead(BATTERY_ADC_PIN);
    delay(2);
  }
  float avgAdc = totalAdc / 20.0;

  digitalWrite(BATTERY_EN_PIN, LOW);

  // Adding 1.074 calibration multiplier to correct physical resistors
  return ((avgAdc / 4095.0) * 3.3 * 2.0) * 1.074;
}

long getSecondsUntilNextWake(int targetHour, int targetMinute) {
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    Serial.println("Error: Failed to obtain time from NTP. Defaulting to 24hr sleep.");
    return 86400;
  }

  long currentSeconds = (timeinfo.tm_hour * 3600) + (timeinfo.tm_min * 60) + timeinfo.tm_sec;
  long targetSeconds = (targetHour * 3600) + (targetMinute * 60);
  long sleepSeconds = targetSeconds - currentSeconds;

  if (sleepSeconds <= 0) {
    sleepSeconds += 86400;
  }
  return sleepSeconds;
}

void setup() {
  Serial.begin(115200);

  // ==========================================
  // ELEGANT WI-FI WIPE (Zero Battery Waste)
  // ==========================================
  esp_sleep_wakeup_cause_t wakeup_reason = esp_sleep_get_wakeup_cause();

  // If the device did NOT wake up from the sleep timer, a human just reset it or powered it on.
  if (wakeup_reason != ESP_SLEEP_WAKEUP_TIMER) {
    Serial.println("\n[Human Reset Detected] You have 10 SECONDS to press the BOOT button...");
    pinMode(0, INPUT_PULLUP);
    bool wipeRequested = false;

    // Give the user a full 10-second window to press the button
    for (int i = 0; i < 100; i++) {
      if (digitalRead(0) == LOW) {
        wipeRequested = true;
        break;  // The moment you press it, it moves on. You don't have to hold it!
      }
      delay(100);
    }

    if (wipeRequested) {
      Serial.println("\n[!] WIPE COMMAND DETECTED: Erasing saved Wi-Fi credentials...");
      WiFiManager wm;
      wm.resetSettings();
      is_gift_transit = true;  // Enable fast-pulse transit mode
      delay(1000);             // Give flash memory time to clear
    } else {
      Serial.println("No wipe requested. Booting normally...");
    }
  }

  // --- RETRIEVE SAVED TIMEZONE ---
  Preferences preferences;
  preferences.begin("frame", false);
  current_timezone = preferences.getString("tz", "EST5EDT,M3.2.0,M11.1.0");
  preferences.end();

  // --- 1. SILENT HEARTBEAT: READ BATTERY FIRST ---
  float batteryVoltage = readBatteryVoltage();
  bool stateChanged = false;
  int previous_state = frame_state;

  // --- ROBUST BATTERY INITIALIZATION & HYSTERESIS ---
  bool isPluggedIn = false;
  bool isUnplugged = false;

  // 1. ESTABLISH BASELINE ON FRESH BOOT
  if (last_voltage == 0.0) {
    // If voltage is abnormally high (4.15V+), the USB charge controller MUST be active.
    // A resting LiPo alone will not sustain 4.15V+ under ESP32 boot load.
    if (batteryVoltage >= 4.15) {
      frame_state = 3;
      peak_charge_voltage = batteryVoltage;
      isPluggedIn = true;
    } else {
      last_voltage = batteryVoltage;
      frame_state = (batteryVoltage <= 3.55) ? 1 : 0;
    }
  }
  // 2. NORMAL OPERATION (Tracking jumps and drops)
  else {
    isPluggedIn = ((batteryVoltage - last_voltage) >= 0.04);

    if (peak_charge_voltage > 0.0) {
      isUnplugged = ((peak_charge_voltage - batteryVoltage) >= 0.02);
    }

    // Only lower the floor, never raise it.
    if ((frame_state == 0 || frame_state == 1) && batteryVoltage < last_voltage) {
      last_voltage = batteryVoltage;
    }

    // Only raise the ceiling, never lower it.
    if ((frame_state == 2 || frame_state == 3) && batteryVoltage > peak_charge_voltage) {
      peak_charge_voltage = batteryVoltage;
    }
  }

  // --- THE STATE MACHINE ---
  if (frame_state == 0) {
    if (batteryVoltage <= 3.55) frame_state = 1;
    else if (isPluggedIn) {
      frame_state = 2;
      peak_charge_voltage = batteryVoltage;
    }
  } else if (frame_state == 1) {
    if (isPluggedIn) {
      frame_state = 2;
      peak_charge_voltage = batteryVoltage;
    } else if (batteryVoltage > 3.60) {
      frame_state = 0;
    }
  } else if (frame_state == 2) {
    if (isUnplugged) {
      frame_state = 0;
      last_voltage = batteryVoltage;
    } else if (batteryVoltage >= 4.15) {
      frame_state = 3;
    }
  } else if (frame_state == 3) {
    if (isUnplugged || batteryVoltage <= 4.13) {
      frame_state = 0;
      last_voltage = batteryVoltage;
    }
  }

  if (frame_state != previous_state) {
    stateChanged = true;
  }

  Serial.printf("Battery: %.2fV | Last: %.2fV | State: %d\n", batteryVoltage, last_voltage, frame_state);

  // --- 2. DECIDE IF WE NEED TO WAKE THE SYSTEM UP ---
  time_t now = time(NULL);
  bool timeForDailyUpdate = (now >= next_midnight_epoch) && (next_midnight_epoch > 0);
  bool isFirstBoot = (next_midnight_epoch == 0);
  bool isRetry = (retryCount > 0 && retryCount < 3);
  bool needsUpdate = stateChanged || timeForDailyUpdate || isFirstBoot || isRetry;

  if (!needsUpdate) {
    if (is_gift_transit) {
      Serial.println("Transit Mode Active: Suppressing network ping to protect battery in the box.");
    } else {
      int ping_threshold = (frame_state == 2 || frame_state == 3) ? 1 : 3;
      network_ping_counter++;

      if (network_ping_counter >= ping_threshold) {
        network_ping_counter = 0;
        Serial.print("Network heartbeat threshold reached. Pinging server...");

        WiFi.mode(WIFI_STA);
        WiFi.begin();

        int timeoutCounter = 0;
        while (WiFi.status() != WL_CONNECTED && timeoutCounter < 10) {
          delay(500);
          Serial.print(".");
          timeoutCounter++;
        }

        if (WiFi.status() == WL_CONNECTED) {
          WiFiClientSecure checkClient;
          checkClient.setInsecure();
          HTTPClient checkHttp;

          String checkUrl = String(image_url);
          checkUrl.replace("gallery.php", "check_update.php");

          checkHttp.begin(checkClient, checkUrl);
          checkHttp.addHeader("X-API-Key", api_key);

          if (checkHttp.GET() == HTTP_CODE_OK && checkHttp.getString() == "1") {
            Serial.println("\nManual update requested by portal!");
            needsUpdate = true;
          } else {
            Serial.println("\nNo updates pending.");
            WiFi.disconnect(true);
            WiFi.mode(WIFI_OFF);
          }
          checkHttp.end();
        } else {
          Serial.println("\nWiFi timeout. Sleeping.");
          WiFi.disconnect(true);
          WiFi.mode(WIFI_OFF);
        }
      } else {
        Serial.printf("Silent hardware heartbeat only. Skipping network ping (%d/%d).\n", network_ping_counter, ping_threshold);
      }
    }
  }

  if (!needsUpdate) {
    uint64_t sleep_time_sec = is_gift_transit ? 15ULL : 300ULL;
    Serial.printf("Heartbeat complete. No update needed. Sleeping %llu seconds...\n", sleep_time_sec);
    esp_sleep_enable_timer_wakeup(sleep_time_sec * 1000000ULL);
    esp_deep_sleep_start();
  }

  // --- 3. FULL SYSTEM WAKEUP (State Change or Midnight) ---
  Serial.println("System Wake Triggered! Booting E-Paper and WiFi...");

  epaper.begin();
  epaper.setRotation(0);
  bool imageSuccess = false;

  Serial.print("Attempting silent connection to stored network...");
  WiFi.mode(WIFI_STA);
  WiFi.begin();

  int timeoutCounter = 0;
  while (WiFi.status() != WL_CONNECTED && timeoutCounter < 20) {
    delay(500);
    Serial.print(".");
    timeoutCounter++;
  }

  if (WiFi.status() != WL_CONNECTED) {
    if (frame_state == 2 || frame_state == 3 || DEV_MODE) {
      Serial.println("\nNo stored network found. Launching safe portal...");
      WiFiManager wm;
      wm.setConfigPortalTimeout(180);

      // GLOBAL TIMEZONE LIST (Hides default textbox, injects custom dropdown)
      const char* custom_html =
        "type='hidden'> <label for='tz'><b>Time Zone</b></label><br/>"
        "<select name='tz' id='tz' style='width:100%; padding:5px;'>"
        "<option value='UTC0'>UTC / GMT</option>"
        "<option value='WAT-1'>Africa - West Africa Time</option>"
        "<option value='SAST-2'>Africa - South Africa Standard</option>"
        "<option value='EAT-3'>Africa - East Africa Time</option>"
        "<option value='IST-5:30'>Asia - India Standard</option>"
        "<option value='WIB-7'>Asia - Western Indonesia</option>"
        "<option value='CST-8'>Asia - China Standard / Beijing</option>"
        "<option value='JST-9'>Asia - Japan Standard</option>"
        "<option value='AEST-10AEDT,M10.1.0,M4.1.0/3'>Australia - Sydney</option>"
        "<option value='CET-1CEST,M3.5.0,M10.5.0/3'>Europe - Central</option>"
        "<option value='GMT0BST,M3.5.0/1,M10.5.0'>Europe - London</option>"
        "<option value='BRT3BRST,M11.1.0/0,M2.3.0/0'>South America - Brasilia</option>"
        "<option value='ART3'>South America - Argentina</option>"
        "<option value='EST5EDT,M3.2.0,M11.1.0'>US & Canada - Eastern</option>"
        "<option value='CST6CDT,M3.2.0,M11.1.0'>US & Canada - Central</option>"
        "<option value='MST7MDT,M3.2.0,M11.1.0'>US & Canada - Mountain</option>"
        "<option value='PST8PDT,M3.2.0,M11.1.0'>US & Canada - Pacific</option>"
        "</select><br";  // Deliberately unclosed bracket for WiFiManager to complete

      WiFiManagerParameter custom_tz("tz", "Time Zone", "", 50, custom_html);
      wm.addParameter(&custom_tz);

      if (!wm.autoConnect("Frame Setup")) {
        Serial.println("Failed to connect or hit timeout.");
      } else {
        Serial.println("Successfully connected via portal!");
        is_gift_transit = false;

        String selected_tz = custom_tz.getValue();
        if (selected_tz.length() > 5) {
          preferences.begin("frame", false);
          preferences.putString("tz", selected_tz);
          preferences.end();
          current_timezone = selected_tz;
          Serial.printf("New Timezone Saved to Flash: %s\n", current_timezone.c_str());
        }
      }
    } else {
      Serial.println("\nNo stored network found, but running on battery.");
      Serial.println("Transit Mode Active: Skipping portal to save power. Device must be plugged in to setup.");
    }
  } else {
    Serial.println("\nConnected silently!");
    is_gift_transit = false;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Syncing clock via NTP...");
    configTzTime(current_timezone.c_str(), "pool.ntp.org", "time.nist.gov");

    time_t currentTime = time(NULL);
    int ntpTimeout = 0;
    while (currentTime < 1600000000 && ntpTimeout < 15) {
      delay(500);
      Serial.print(".");
      currentTime = time(NULL);
      ntpTimeout++;
    }
    Serial.println();

    long secondsToMidnight = getSecondsUntilNextWake(0, 5);
    next_midnight_epoch = (uint32_t)(currentTime + secondsToMidnight);

    imageSuccess = fetchAndDisplayImage(batteryVoltage, frame_state);
  }

  // --- 4. POST-UPDATE CLEANUP & SLEEP ---
  if (DEV_MODE) {
    Serial.println("DEV MODE ACTIVE: Bypassing deep sleep to keep USB alive.");
    delay(30000);
    ESP.restart();
  } else {
    if (!imageSuccess) {
      retryCount++;
      Serial.printf("Refresh failed. Attempt %d of 3.\n", retryCount);
    } else {
      retryCount = 0;
    }

    Serial.println("Tasks complete. Heartbeat resuming...");
    Serial.flush();
    delay(500);

    uint64_t sleep_time_sec = is_gift_transit ? 15ULL : 300ULL;
    Serial.printf("Sleeping %llu seconds...\n", sleep_time_sec);
    esp_sleep_enable_timer_wakeup(sleep_time_sec * 1000000ULL);
    esp_deep_sleep_start();
  }
}

void loop() {}

// Accept voltage and state as parameters
bool fetchAndDisplayImage(float batteryVoltage, int state) {
  if (WiFi.status() == WL_CONNECTED) {

    // 1. STACK ALLOCATION: Automatically cleans up when function exits
    WiFiClientSecure client;
    client.setInsecure();
    HTTPClient http;

    String fullUrl = String(image_url) + "?v=" + String(batteryVoltage, 2) + "&state=" + String(state);

    Serial.print("Connecting to: ");
    Serial.println(fullUrl);

    http.begin(client, fullUrl.c_str());
    http.addHeader("X-API-Key", api_key);

    int httpCode = http.GET();
    bool success = false;

    if (httpCode == HTTP_CODE_OK) {
      int totalBytes = http.getSize();
      Serial.printf("\n--- NEW DOWNLOAD STARTING ---\n");
      Serial.printf("Expected file size: %d bytes\n", totalBytes);

      if (totalBytes <= 0) {
        Serial.println("FATAL ERROR: Server did not send a valid Content-Length.");
        Serial.println(http.getString());  // Print the hidden PHP error
        http.end();
        return false;  // No need to manually delete objects here anymore!
      }

      uint8_t* imgBuffer = (uint8_t*)ps_malloc(totalBytes);

      if (imgBuffer != NULL) {
        WiFiClient* stream = http.getStreamPtr();
        int bytesRead = 0;

        // 2. SAFETY TIMEOUT: Protects against the infinite loop
        unsigned long timeoutStart = millis();

        while (http.connected() && bytesRead < totalBytes) {
          size_t available = stream->available();
          if (available) {
            if (bytesRead + available > totalBytes) { available = totalBytes - bytesRead; }
            int c = stream->readBytes(imgBuffer + bytesRead, available);
            bytesRead += c;
            timeoutStart = millis();  // Reset timeout clock because we got good data
          } else {
            if (millis() - timeoutStart > 10000) {
              Serial.println("WARNING: Network stalled. Aborting download to prevent battery drain.");
              break;
            }
          }
          delay(1);
        }

        if (bytesRead == totalBytes) {
          Serial.println("Attempting to open PNG in RAM...");
          int rc = png.openRAM(imgBuffer, bytesRead, pngDraw);

          if (rc == PNG_SUCCESS) {
            Serial.println("Valid PNG verified. Clearing screen to white...");
            epaper.fillScreen(TFT_WHITE);

            Serial.println("Starting full decode...");
            int decodeResult = png.decode(NULL, 0);
            Serial.printf("Decode finished with code: %d\n", decodeResult);
            png.close();

            Serial.println("Pushing voltage update to E-Ink hardware...");
            epaper.update();
            Serial.println("Image refresh complete!\n");

            success = true;
          } else {
            Serial.printf("FATAL ERROR: openRAM failed! Code: %d. Leaving old image on screen.\n", rc);
          }
        } else {
          Serial.println("FATAL ERROR: Download incomplete. Aborting display update.");
        }

        free(imgBuffer);  // We still must manually free PSRAM allocations
      } else {
        Serial.println("FATAL ERROR: ESP32 ran out of PSRAM!");
      }
    } else {
      Serial.printf("HTTP Request Failed. Code: %d\n", httpCode);
    }

    http.end();
    return success;
  }

  Serial.println("WiFi not connected. Cannot fetch image.");
  return false;
}