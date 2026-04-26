import pytest
import numpy as np
from utils.detector import FaceDetector

def test_face_detector_initialization():
    """
    Kiểm tra việc khởi tạo FaceDetector (RetinaFace).
    Không bị lỗi khi khởi tạo.
    """
    try:
        detector = FaceDetector()
        assert detector is not None
        print("\nPass: Khởi tạo FaceDetector thành công")
    except Exception as e:
        pytest.fail(f"\nFail: Khởi tạo FaceDetector thất bại - {e}")

def test_face_detector_detect(dummy_image):
    """
    Kiểm tra chức năng nhận diện bằng ảnh giả định.
    RetinaFace thường trả về list rỗng khi đưa ảnh trắng (không có mặt).
    """
    detector = FaceDetector()
    try:
        faces = detector.detect(dummy_image)
        assert isinstance(faces, list)
        print("\nPass: Hàm detect() trả về đúng định dạng danh sách (list)")
    except Exception as e:
        pytest.fail(f"\nFail: Hàm detect() gây lỗi - {e}")
