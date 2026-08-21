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

const bool DEV_MODE = false;

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

  // --- RETRIEVE SAVED TIMEZONE ---
  Preferences preferences;
  preferences.begin("frame", false);                                          // Open in read-only mode
  current_timezone = preferences.getString("tz", "EST5EDT,M3.2.0,M11.1.0");  // Default to EST if missing
  preferences.end();

  // --- 1. SILENT HEARTBEAT: READ BATTERY FIRST ---
  // Notice we have NOT initialized the screen or Wi-Fi yet.
  float batteryVoltage = readBatteryVoltage();
  bool stateChanged = false;
  int previous_state = frame_state;

  // --- THE ADAPTIVE HYSTERESIS ENGINE ---

  // 1. DETECT CHARGER
  bool isPluggedIn = false;
  if (last_voltage > 0.0) {
    isPluggedIn = ((batteryVoltage - last_voltage) >= 0.04);
  }

  // 2. DETECT UNPLUG
  bool isUnplugged = false;
  if (peak_charge_voltage > 0.0) {
    // Even at 100%, physically severing the 5V USB cord causes an immediate drop of at least 0.02V
    isUnplugged = ((peak_charge_voltage - batteryVoltage) >= 0.02);
  }

  // 3. UPDATE FLOOR (Anchored)
  if (frame_state == 0 || frame_state == 1) {
    // CRITICAL: Only lower the floor. Never raise it.
    // This allows a slow trickle charge to accumulate until it trips the trigger.
    if (last_voltage == 0.0 || batteryVoltage < last_voltage) {
      last_voltage = batteryVoltage;
    }
  }

  // 4. UPDATE CEILING
  if (frame_state == 2 || frame_state == 3) {
    if (peak_charge_voltage == 0.0 || batteryVoltage > peak_charge_voltage) {
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
    // SAFETY NET: If the tiny 0.02V unplug drop is missed, guarantee the green screen
    // clears automatically once the battery drops below true full capacity.
    if (isUnplugged || batteryVoltage <= 4.13) {
      frame_state = 0;
      last_voltage = batteryVoltage;
    }
  }

  // Check if the state machine actually moved us to a new state
  if (frame_state != previous_state) {
    stateChanged = true;
  }

  Serial.printf("Battery: %.2fV | Last: %.2fV | State: %d\n", batteryVoltage, last_voltage, frame_state);

  // --- 2. DECIDE IF WE NEED TO WAKE THE SYSTEM UP ---
  // The ESP32 RTC clock keeps tracking time even in deep sleep.
  time_t now = time(NULL);

  // Did we cross the midnight update target?
  bool timeForDailyUpdate = (now >= next_midnight_epoch) && (next_midnight_epoch > 0);
  // Is this the very first boot?
  bool isFirstBoot = (next_midnight_epoch == 0);
  // Are we trying to recover from a failed image download?
  bool isRetry = (retryCount > 0 && retryCount < 3);

  bool needsUpdate = stateChanged || timeForDailyUpdate || isFirstBoot || isRetry;

  // ==========================================
  // Decoupled Network Ping for Manual Updates
  // ==========================================
  if (!needsUpdate) {
    if (is_gift_transit) {
      Serial.println("Transit Mode Active: Suppressing network ping to protect battery in the box.");
    } else {
      // If charging (state 2), check every 1 cycle (5 mins).
      // If on battery, check every 3 cycles (15 mins).
      int ping_threshold = (frame_state == 2) ? 1 : 3;
      network_ping_counter++;

      if (network_ping_counter >= ping_threshold) {
        network_ping_counter = 0;  // Reset the counter

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
  // ==========================================

  if (!needsUpdate) {
    // We are done. Go back to sleep immediately. Total awake time: < 0.1 seconds.
    uint64_t sleep_time_sec = is_gift_transit ? 15ULL : 300ULL;
    Serial.printf("Heartbeat complete. No update needed. Sleeping %llu seconds...\n", sleep_time_sec);
    esp_sleep_enable_timer_wakeup(sleep_time_sec * 1000000ULL);
    esp_deep_sleep_start();
  }

  // --- 3. FULL SYSTEM WAKEUP (State Change or Midnight) ---
  Serial.println("System Wake Triggered! Booting E-Paper and WiFi...");

  Serial.printf("\n--- WAKE DIAGNOSTICS ---\n");
  Serial.printf("State Changed: %s\n", stateChanged ? "YES" : "NO");
  Serial.printf("Daily Time Hit: %s\n", timeForDailyUpdate ? "YES" : "NO");
  Serial.printf("First Boot: %s\n", isFirstBoot ? "YES" : "NO");
  Serial.printf("Retry Trigger: %s\n", isRetry ? "YES" : "NO");
  Serial.printf("REQUESTING PHP STATE: %d\n", frame_state);
  Serial.printf("------------------------\n\n");

  epaper.begin();
  epaper.setRotation(0);
  bool imageSuccess = false;

  // ==========================================
  // MANUAL WI-FI WIPE (Gift Prep)
  // Hold the D0 (usually BOOT) button while waking up to clear memory
  // ==========================================
  pinMode(D0, INPUT_PULLUP);
  if (digitalRead(D0) == LOW) {
    Serial.println("\n[!] WIPE COMMAND DETECTED: Erasing saved Wi-Fi credentials...");
    WiFiManager wm;
    wm.resetSettings();
    is_gift_transit = true;  // Enable fast-pulse transit mode
    delay(1000);             // Give it a second to clear flash memory
  }

  Serial.print("Attempting silent connection to stored network...");
  WiFi.mode(WIFI_STA);
  WiFi.begin();

  int timeoutCounter = 0;
  while (WiFi.status() != WL_CONNECTED && timeoutCounter < 20) {
    delay(500);
    Serial.print(".");
    timeoutCounter++;
  }

  // ---> THIS IS THE LINE THAT GOT DELETED! <---
  if (WiFi.status() != WL_CONNECTED) {

    if (frame_state == 2 || frame_state == 3) {
      Serial.println("\nNo stored network found. Launching safe portal...");
      WiFiManager wm;
      wm.setConfigPortalTimeout(180);

      // 1. Build the HTML dropdown menu for the portal
      const char* custom_html =
        "<br/><label for='tz'><b>Time Zone</b></label><br/>"
        "<select name='tz' id='tz' style='width:100%; padding:5px;'>"
        "<option value='EST5EDT,M3.2.0,M11.1.0'>Eastern Time</option>"
        "<option value='CST6CDT,M3.2.0,M11.1.0'>Central Time</option>"
        "<option value='MST7MDT,M3.2.0,M11.1.0'>Mountain Time</option>"
        "<option value='PST8PDT,M3.2.0,M11.1.0'>Pacific Time</option>"
        "</select><br/>";

      // 2. Inject it into WiFiManager
      WiFiManagerParameter custom_tz("tz", custom_html, "", 50, " \n");
      wm.addParameter(&custom_tz);

      if (!wm.autoConnect("Frame Setup")) {
        Serial.println("Failed to connect or hit timeout.");
      } else {
        Serial.println("Successfully connected via portal!");
        is_gift_transit = false;  // Turn off transit mode

        // 3. Catch the dropdown selection and save it permanently to flash memory!
        String selected_tz = custom_tz.getValue();
        if (selected_tz.length() > 5) {
          preferences.begin("frame", false);  // Open in read/write mode
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

  }  // <--- This brace now properly closes the missing IF statement
  else {
    Serial.println("\nConnected silently!");
    is_gift_transit = false;  // Turn off transit mode if connected normally
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Syncing clock via NTP...");
    configTzTime(current_timezone.c_str(), "pool.ntp.org", "time.nist.gov");

    // Wait up to 7.5 seconds for NTP to lock (Epoch time > 1.6 billion = year 2020+)
    time_t currentTime = time(NULL);
    int ntpTimeout = 0;
    while (currentTime < 1600000000 && ntpTimeout < 15) {
      delay(500);
      Serial.print(".");
      currentTime = time(NULL);
      ntpTimeout++;
    }
    Serial.println();

    if (currentTime < 1600000000) {
      Serial.println("WARNING: NTP sync failed! Midnight calculation will be wrong.");
    }

    // Calculate tomorrow's midnight update and safely cast it to 32-bit for RTC memory
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
    // Force the USB buffer to empty before killing power
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