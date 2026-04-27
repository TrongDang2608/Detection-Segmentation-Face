import pytest
import numpy as np
from utils.segmentor import FaceSegmentor

def test_segmentor_initialization():
    """
    Kiểm tra việc khởi tạo FaceSegmentor và tải mô hình BiSeNet.
    """
    try:
        # Đường dẫn weights có thể không tồn tại trong môi trường test CI, 
        # nhưng ở local thì đang cấu hình trỏ tới file 79999_iter.pth
        segmentor = FaceSegmentor(weight_path='weights/79999_iter.pth')
        assert segmentor is not None
        assert segmentor.model is not None
        print("\nPass: Khởi tạo FaceSegmentor và model BiSeNet thành công")
    except Exception as e:
        pytest.fail(f"\nFail: Khởi tạo FaceSegmentor thất bại - {e}")

def test_segmentor_segment(dummy_image):
    """
    Kiểm tra hàm phân vùng chạy được và trả về numpy array với kích thước đúng.
    """
    segmentor = FaceSegmentor(weight_path='weights/79999_iter.pth')
    try:
        mask, _ = segmentor.segment(dummy_image)
        assert isinstance(mask, np.ndarray)
        assert mask.shape == dummy_image.shape[:2]  # Kích thước chiều cao và rộng khớp
        print("\nPass: Hàm segment() trả về mask đúng định dạng và kích thước")
    except Exception as e:
        pytest.fail(f"\nFail: Hàm segment() gây lỗi - {e}")
