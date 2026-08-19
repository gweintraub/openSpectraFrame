import sys
import cv2
import numpy as np
import mediapipe as mp

input_path = sys.argv[1]
fg_mask_path = sys.argv[2]
bg_mask_path = sys.argv[3]

mp_selfie = mp.solutions.selfie_segmentation

with mp_selfie.SelfieSegmentation(model_selection=0) as segmentation:
    image = cv2.imread(input_path)
    if image is None:
        sys.exit(1)

    image_rgb = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
    results = segmentation.process(image_rgb)

    if results.segmentation_mask is not None:
        mask = results.segmentation_mask
        binary_fg = np.where(mask > 0.5, 255, 0).astype(np.uint8)
    else:
        binary_fg = np.zeros((image.shape[0], image.shape[1]), dtype=np.uint8)

    binary_bg = cv2.bitwise_not(binary_fg)

    feathered_fg = cv2.GaussianBlur(binary_fg, (51, 51), 0)
    feathered_bg = cv2.GaussianBlur(binary_bg, (51, 51), 0)

    cv2.imwrite(fg_mask_path, feathered_fg)
    cv2.imwrite(bg_mask_path, feathered_bg)
