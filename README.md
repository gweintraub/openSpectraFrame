# Smart Frame & Web Portal
A full-stack IoT system featuring an ESP32 physical hardware display and a PHP/Nginx web management portal.

## 📁 Repository Structure

```text
├── firmware/ # ESP32 Arduino C++ source code
├── web/ # PHP web portal & API endpoints
├── compose.yaml # Docker Compose setup for deployment
├── .env.example # Safe template for Docker environment variables
└── README.md

## Hardware Setup & Flashing
This project uses an ESP32 connected to an E-Ink display. Because the high-resolution images are larger than the ESP32's internal memory, **PSRAM is strictly required.**

**Arduino IDE Settings:**
Before clicking Upload, ensure your board settings are correct:
* **Board:** XIAO ESP32-S3
* **PSRAM:** Enabled (or OPI PSRAM)
* **Upload Speed:** 9600 (or leave as default)
> **Note:** If PSRAM is disabled in the tools menu, the board will throw a 'FATAL ERROR: ESP32 ran out of PSRAM!' crash over the Serial monitor when it attempts to download a photo.