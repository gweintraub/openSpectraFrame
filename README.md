## System Architecture

This diagram illustrates how the hardware, web portal, and AI processing pipeline interact. The ESP32 acts as a lightweight client, offloading all machine learning, image processing, and telemetry tracking to the Docker container.

```
       [ Human User ]                                  [ E-Ink Photo Frame ]
        (Web Browser)                                   (XIAO ESP32-S3)
              │                                                │
  (Uploads photos, triggers                       (Sends battery telemetry, downloads
   updates via web portal)                         final formatted PNG via API)
              │                                                │
              ▼                                                ▼
      [ Cloudflare Tunnel ]  <────────────────────>  [ Local Wi-Fi Router ]
              │                                                │
              ▼                                                ▼
 ┌─────────────────────────────────────────────────────────────────────────┐
 │                            SYNOLOGY NAS                                 │
 │ ┌─────────────────────────────────────────────────────────────────────┐ │
 │ │                        DOCKER CONTAINER                             │ │
 │ │                                                                     │ │
 │ │  [ Processing Pipeline ]                [ Delivery API ]            │ │
 │ │  (index.php, prepare_photo.php)         (gallery.php)               │ │
 │ │          │                                    ▲                     │ │
 │ │          ▼                                    │                     │ │
 │ │  [ AI Masking ]                         [ Dynamic UI Engine ]       │ │
 │ │  (masker.py via MediaPipe)              (GD Library Overlays)       │ │
 │ │          │                                    ▲                     │ │
 │ │          ▼                                    │                     │ │
 │ │  [ File Storage ] ────────────────────────────┘                     │ │
 │ │  (/photos, frame_state.json, ready_for_frame.png)                   │ │
 │ └─────────────────────────────────────────────────────────────────────┘ │
 └─────────────────────────────────────────────────────────────────────────┘
```

## Repository Structure

```
├── firmware/                   
│   └── spectra_frame/          # ESP32 C++ Source Code
│       ├── spectra_frame.ino   # Main state machine and sleep logic
│       ├── driver.h            # E-Ink display driver definitions
│       └── secrets.h.example   # Template for Wi-Fi and API keys
│
├── web/                        # PHP/Python Backend Engine
│   ├── index.php               # Secure user UI for uploading & managing photos
│   ├── gallery.php             # ESP32 Endpoint: Telemetry & dynamic UI overlay
│   ├── prepare_photo.php       # Image pipeline trigger
│   ├── process_dropbox.php     # Manual trigger to flag pending updates
│   ├── check_update.php        # ESP32 Endpoint: Lightweight heartbeat check
│   ├── logout.php              # UI Authentication handler
│   ├── config.php.example      # Template for system variables and passwords
│   ├── masker.py               # AI script: MediaPipe/OpenCV subject isolation
│   ├── OpenSans-Medium.ttf     # Font used for E-Ink hardware alerts
│   ├── OpenSans-Regular.ttf    # Standard font for UI/Overlays
│   └── img/                    # Web portal branding assets (logos, icons)
│
├── Dockerfile                  # Custom build (PHP 8.2 + ImageMagick + Python AI)
├── docker-compose.yml          # Docker Compose setup for NAS deployment
├── nginx.conf                  # Custom web server routing and configuration
├── .env.example                # Template for Docker environment variables
├── .gitignore                  # Keeps personal data out of the repo
├── LICENSE                     # MIT License
└── README.md                   # Project documentation
```

## Core Components

| Component | Technology | Responsibility |
| :--- | :--- | :--- |
| **Hardware** | XIAO ESP32-S3 | Manages deep sleep, tracks battery voltage, and drives the E-Ink display via SPI. Requires PSRAM to hold the downloaded image buffer. |
| **AI Processing** | Python / MediaPipe | `masker.py` uses machine learning to isolate human subjects from backgrounds, creating 51px feathered masks for depth-of-field effects. |
| **Image Engine** | ImageMagick | Composites the AI masks, crops to the exact E-Ink aspect ratio, and converts the final output to a raw RGB565 palette. |
| **Delivery API** | PHP GD | `gallery.php` intercepts the frame's download request, logs hardware telemetry, and dynamically draws text overlays in RAM before sending. |

## Hardware Setup & Flashing
This project uses an ESP32 connected to an E-Ink display. Because the high-resolution images are larger than the ESP32's internal memory, **PSRAM is strictly required.**

**Arduino IDE Settings:**
Before clicking Upload, ensure your board settings are correct:
* **Board:** XIAO ESP32-S3
* **PSRAM:** Enabled (or OPI PSRAM)
* **Upload Speed:** 9600 (or leave as default)
> **Note:** If PSRAM is disabled in the tools menu, the board will throw a 'FATAL ERROR: ESP32 ran out of PSRAM!' crash over the Serial monitor when it attempts to download a photo.

## Manual Update Triggers (The "Dropbox" System)

Because the ESP32 spends 99% of its life in deep sleep to conserve battery, the web server cannot "push" an update to it. Instead, we use a literal dropbox flag system:
1. When a user forces an update via the web UI, the portal calls `process_dropbox.php`.
2. This script drops a flag on the server indicating an image is waiting.
3. During daytime network pings, the ESP32 wakes up silently and hits `check_update.php`. 
4. If `check_update.php` sees the flag, it tells the frame to fully wake up, download the new photo via `gallery.php`, and refresh the screen off-schedule.
