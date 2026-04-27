import sys, os
os.environ['PROTOCOL_BUFFERS_PYTHON_IMPLEMENTATION'] = 'python'
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))
import pytest
import numpy as np

@pytest.fixture
def dummy_image():
    """
    Tạo một ảnh RGB giả định (kích thước 100x100) để dùng cho kiểm thử.
    Tránh phải tải ảnh thật từ ổ đĩa khi chạy unit test.
    """
    # Ảnh màu trắng hoàn toàn
    img = np.ones((100, 100, 3), dtype=np.uint8) * 255
    return img
