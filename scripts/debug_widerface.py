import cv2
import os
import sys
import numpy as np
import time

# Thêm thư mục gốc vào path để import utils
sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

from utils.detector import FaceDetector

def run_debug_hard_mode():
    detector = FaceDetector()
    # Ảnh diễu hành siêu khó với 176 faces nhỏ
    image_path = "data/wider_face/WIDER_val/WIDER_val/images/0--Parade/0_Parade_marchingband_1_78.jpg"
    
    if not os.path.exists(image_path):
        print(f"Lỗi: Không tìm thấy ảnh tại {image_path}")
        return

    img_bgr = cv2.imread(image_path)
    img_rgb = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)

    print(f"Đang phân tích ảnh siêu khó: {image_path}")
    
    # 1. Thử nghiệm chế độ THƯỜNG (Upscale 1.5x)
    print("\n--- CHẠY CHẾ ĐỘ THƯỜNG (NORMAL) ---")
    faces_normal = detector.detect(img_rgb, upscale_factor=1.5, use_hard_mode=False)
    
    # 2. Thử nghiệm chế độ HARD (Tiling 4x4)
    print("\n--- CHẠY CHẾ ĐỘ HARD (TILING 4x4) ---")
    # Chúng ta chia lưới 4x4 để mỗi ô đủ nhỏ cho RetinaFace bắt được mặt 4x6px
    faces_hard = detector.detect_hard_mode(img_rgb, grid_size=(4, 4), overlap=0.2)

    # 3. Kết quả so sánh
    print("\n" + "="*40)
    print(f"{'Phương pháp':<20} | {'Số lượng Face':<15}")
    print("-" * 40)
    print(f"{'Normal Mode':<20} | {len(faces_normal):<15}")
    print(f"{'HARD Mode (Tiling)':<20} | {len(faces_hard):<15}")
    print("="*40)

    # Vẽ kết quả HARD mode
    img_res = detector.draw_faces(img_rgb, faces_hard, color=(0, 255, 0))
    
    output_path = "data/output_images/debug_hard_mode.jpg"
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    cv2.imwrite(output_path, cv2.cvtColor(img_res, cv2.COLOR_RGB2BGR))
    print(f"\nĐã lưu ảnh kết quả HARD mode tại: {output_path}")

if __name__ == "__main__":
    run_debug_hard_mode()
