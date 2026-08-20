# Smart Frame & Web Portal
A full-stack IoT system featuring an ESP32 physical hardware display and a PHP/Nginx web management portal.

## 📁 Repository Structure

```text
├── firmware/ # ESP32 C++ Source Code
│ ├── firmware.ino # Main state machine and sleep logic
│ └── secrets.example.h # Template for Wi-Fi and API keys
│
├── web/ # PHP/Python Backend Engine
│ ├── index.php # Secure user UI for uploading & managing photos
│ ├── prepare_photo.php # Image pipeline trigger
│ ├── masker.py # AI script: MediaPipe/OpenCV subject isolation
│ ├── gallery.php # ESP32 Endpoint: Telemetry & dynamic UI overlay
│ ├── check_update.php # ESP32 Endpoint: Lightweight heartbeat check
│ ├── config.example.php # Template for system variables and passwords
│ ├── OpenSans-Medium.ttf # Font used for E-Ink hardware alerts
│ ├── /photos/ # (Generated) Local storage for user images
│ └── /sessions/ # (Generated) Secure storage for auth cookies
│
├── Dockerfile # Custom build (PHP 8.2 + ImageMagick + Python AI)
├── compose.yaml # Docker Compose setup for NAS deployment
├── .env.example # Template for Docker environment variables
├── .gitignore # Keeps personal data out of the repo
├── LICENSE # MIT License
└── README.md # Project documentation

## System Architecture
flowchart TD
    User([Human User / Web Browser]) -->|Uploads photos & triggers updates| Tunnel{Cloudflare Tunnel}
    Frame([E-Ink Frame / XIAO ESP32-S3]) -->|Sends telemetry & fetches PNG| Router{Local Wi-Fi Router}

    Tunnel <--> Router
    Router <--> Docker

    subgraph Docker [Synology NAS: Docker Container]
        direction TB
        Pipeline[Processing Pipeline<br>index.php, prepare_photo.php] --> AI[AI Masking<br>masker.py via MediaPipe]
        API[Delivery API<br>gallery.php] --> Engine[Dynamic UI Engine<br>GD Library Overlays]

        AI --> Storage[(File Storage<br>/photos, json, png)]
        Engine --> Storage
    end

## Hardware Setup & Flashing
This project uses an ESP32 connected to an E-Ink display. Because the high-resolution images are larger than the ESP32's internal memory, **PSRAM is strictly required.**

**Arduino IDE Settings:**
Before clicking Upload, ensure your board settings are correct:
* **Board:** XIAO ESP32-S3
* **PSRAM:** Enabled (or OPI PSRAM)
* **Upload Speed:** 9600 (or leave as default)
> **Note:** If PSRAM is disabled in the tools menu, the board will throw a 'FATAL ERROR: ESP32 ran out of PSRAM!' crash over the Serial monitor when it attempts to download a photo.