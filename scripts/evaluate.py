import os
import cv2
import numpy as np
from tqdm import tqdm
from utils.detector import FaceDetector
from utils.segmentor import FaceSegmentor

def evaluate_detection(dataset_dir="data/wider_face/"):
    """
    Kịch bản mẫu để đánh giá Detection trên tập WIDER FACE.
    """
    print("=== Đánh giá Detection trên WIDER FACE ===")
    if not os.path.exists(dataset_dir):
        print(f"Không tìm thấy thư mục: {dataset_dir}")
        return
        
    detector = FaceDetector()
    images = []
    for root, _, files in os.walk(dataset_dir):
        for file in files:
            if file.endswith(('.jpg', '.png', '.jpeg')):
                images.append(os.path.join(root, file))
                
    if not images:
        print("Không tìm thấy ảnh nào trong thư mục WIDER FACE.")
        return
        
    total_faces = 0
    # Chạy trên một số lượng ảnh nhất định để làm mẫu
    for img_path in tqdm(images[:100], desc="Detecting"):
        img = cv2.imread(img_path)
        if img is None: continue
        img_rgb = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
        faces = detector.detect(img_rgb)
        total_faces += len(faces)
        
    print(f"Đã hoàn thành đánh giá. Tổng khuôn mặt nhận diện được: {total_faces}")

def evaluate_segmentation(dataset_dir="data/celebamask_hq/"):
    """
    Kịch bản mẫu để đánh giá Segmentation trên tập CelebAMask-HQ.
    """
    print("=== Đánh giá Segmentation trên CelebAMask-HQ ===")
    if not os.path.exists(dataset_dir):
        print(f"Không tìm thấy thư mục: {dataset_dir}")
        return
        
    segmentor = FaceSegmentor()
    images = []
    for root, _, files in os.walk(dataset_dir):
        for file in files:
            if file.endswith(('.jpg', '.png', '.jpeg')):
                images.append(os.path.join(root, file))
                
    if not images:
        print("Không tìm thấy ảnh nào trong thư mục CelebAMask-HQ.")
        return
        
    processed = 0
    # Chạy trên một số lượng ảnh nhất định
    for img_path in tqdm(images[:100], desc="Segmenting"):
        img = cv2.imread(img_path)
        if img is None: continue
        img_rgb = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
        mask = segmentor.segment(img_rgb)
        if mask is not None:
            processed += 1
            
    print(f"Đã hoàn thành đánh giá. Số ảnh đã xử lý: {processed}")

if __name__ == "__main__":
    evaluate_detection()
    evaluate_segmentation()
