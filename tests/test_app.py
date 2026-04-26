import pytest
import os

def test_app_file_exists():
    """
    Kiểm tra xem file app.py có tồn tại trong dự án không.
    """
    assert os.path.exists("app.py")
    print("\nPass: Đã tìm thấy file app.py")

# Không dùng trực tiếp pytest để chạy app.py vì Streamlit chạy theo luồng riêng
# Chúng ta chỉ verify sự tồn tại của các components.
