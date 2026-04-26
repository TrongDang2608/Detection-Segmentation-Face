import cv2
import numpy as np
from retinaface import RetinaFace

class FaceDetector:
    """
    Lớp FaceDetector sử dụng thư viện RetinaFace để nhận diện khuôn mặt.
    """
    def __init__(self):
        # RetinaFace không cần khởi tạo đối tượng trước (sẽ dùng hàm tĩnh)
        pass

    def detect(self, img_rgb):
        """
        Nhận diện khuôn mặt trong ảnh.
        
        Args:
            img_rgb (numpy.ndarray): Ảnh đầu vào với định dạng màu RGB.
            
        Returns:
            list: Danh sách các khuôn mặt nhận diện được.
                  Mỗi đối tượng là một dictionary chứa 'facial_area' là tọa độ bounding box.
        """
        try:
            # RetinaFace tự động xử lý trên ảnh RGB/BGR
            # Trả về dict: {'face_1': {'score': ..., 'facial_area': [x1, y1, x2, y2], ...}, ...}
            faces_dict = RetinaFace.detect_faces(img_rgb)
            
            # Nếu có khuôn mặt, RetinaFace trả về dict, ngược lại trả về tuple ()
            if isinstance(faces_dict, dict):
                return list(faces_dict.values())
            return []
        except Exception as e:
            print(f"Lỗi khi nhận diện khuôn mặt bằng RetinaFace: {e}")
            return []

    def draw_faces(self, img_rgb, faces):
        """
        Vẽ Bounding Box lên ảnh.
        
        Args:
            img_rgb (numpy.ndarray): Ảnh gốc (RGB).
            faces (list): Danh sách khuôn mặt nhận diện được từ RetinaFace.
            
        Returns:
            numpy.ndarray: Ảnh đã được vẽ bounding box.
        """
        res_img = img_rgb.copy()
        for face in faces:
            # Lấy toạ độ box từ dictionary của RetinaFace
            box = face['facial_area']
            # Vẽ HCN bao quanh khuôn mặt (màu xanh lá)
            cv2.rectangle(res_img, (box[0], box[1]), (box[2], box[3]), (0, 255, 0), 2)
        return res_img
