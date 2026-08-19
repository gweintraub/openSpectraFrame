# 1. Use the Debian-based version of your current image
FROM webdevops/php-nginx:8.2

# 2. Update the system and install our dependencies
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    imagemagick \
    libglib2.0-0 \
    libgl1-mesa-glx \
    && rm -rf /var/lib/apt/lists/*

# Debian uses ImageMagick v6 by default, which uses the "convert" command instead of "magick".
# We create a symlink here so your PHP script's "magick" commands still work perfectly.
RUN ln -s /usr/bin/convert /usr/bin/magick

# 3. Setup a Python Virtual Environment
# Modern Debian security blocks system-wide pip installs, so we create an isolated environment
ENV VIRTUAL_ENV=/opt/venv
RUN python3 -m venv $VIRTUAL_ENV
ENV PATH="$VIRTUAL_ENV/bin:$PATH"

# 4. Install the Machine Learning packages
# "headless" ensures OpenCV doesn't crash trying to find a GUI monitor
RUN pip install --no-cache-dir opencv-python-headless mediapipe==0.10.14