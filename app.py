import streamlit as st
import cv2
import numpy as np
from PIL import Image
import os
from utils.detector import FaceDetector
from utils.segmentor import FaceSegmentor

# Thiết lập cấu hình trang
st.set_page_config(page_title="Hệ thống Nhận diện và Phân vùng Khuôn mặt", layout="wide")
st.title("Nhận diện và Phân vùng Khuôn mặt (Tối ưu CPU)")
st.write("Sử dụng RetinaFace cho nhận diện và BiSeNet cho phân vùng.")

# Khởi tạo các model và cache chúng để không bị load lại mỗi khi tương tác UI
@st.cache_resource
def load_models():
    detector = FaceDetector()
    segmentor = FaceSegmentor()
    return detector, segmentor

detector, segmentor = load_models()

# Tạo giao diện upload ảnh
uploaded_file = st.file_uploader("Tải lên một bức ảnh", type=["jpg", "jpeg", "png"])

if uploaded_file is not None:
    # Đọc ảnh từ file upload
    image = Image.open(uploaded_file).convert('RGB')
    img_rgb = np.array(image)
    
    st.write("Đang xử lý...")
    
    # Thực hiện nhận diện
    faces = detector.detect(img_rgb)
    
    # Thực hiện phân vùng
    mask = segmentor.segment(img_rgb)
    
    # Vẽ kết quả
    img_det = detector.draw_faces(img_rgb, faces)
    img_res = segmentor.draw_segmentation(img_det, mask)
    
    st.write(f"Đã phát hiện **{len(faces)}** khuôn mặt trong ảnh.")
    
    # Hiển thị ảnh gốc và kết quả cạnh nhau
    col1, col2 = st.columns(2)
    with col1:
        st.header("Ảnh Gốc")
        st.image(img_rgb, use_container_width=True)
    with col2:
        st.header("Kết Quả Xử Lý")
        st.image(img_res, use_container_width=True)
