import streamlit as st
import cv2
import numpy as np
from PIL import Image
import os
from utils.detector import FaceDetector
from utils.segmentor import FaceSegmentor

# Thiết lập cấu hình trang
st.set_page_config(page_title="Hệ thống Nhận diện và Phân vùng Khuôn mặt", layout="wide")
st.title("Nhận diện và Phân vùng Khuôn mặt")
st.write("Sử dụng RetinaFace cho nhận diện và BiSeNet cho phân vùng.")

# Khởi tạo các model và cache chúng để không bị load lại mỗi khi tương tác UI
@st.cache_resource
def load_models():
    detector = FaceDetector()
    segmentor = FaceSegmentor()
    return detector, segmentor

detector, segmentor = load_models()

# Tạo 2 tab chức năng riêng biệt
tab1, tab2 = st.tabs(["Nhận diện khuôn mặt (Detection)", "Phân vùng khuôn mặt (Segmentation)"])

with tab1:
    st.header("Chức năng Nhận diện (Face Detection)")
    st.write("Sử dụng RetinaFace để tìm kiếm và đóng khung khuôn mặt.")
    # Tùy chọn chế độ nhận diện
    col_opt1, col_opt2 = st.columns(2)
    with col_opt1:
        use_hard_mode = st.checkbox("Bật chế độ HARD (Tiling)", value=False, 
                                    help="Dùng cho ảnh đám đông có hàng trăm khuôn mặt siêu nhỏ. Xử lý sẽ chậm hơn.")
    
    upload_det = st.file_uploader("Tải lên ảnh để Nhận diện", type=["jpg", "jpeg", "png"], key="upload_det")
    
    if upload_det is not None:
        image = Image.open(upload_det).convert('RGB')
        img_rgb = np.array(image)
        
        with st.spinner("Đang xử lý nhận diện..."):
            if use_hard_mode:
                st.warning("Đang chạy chế độ HARD (Tiling) tối ưu. Vui lòng đợi...")
                faces = detector.detect_hard_mode(img_rgb, grid_size=(4, 4))
            else:
                faces = detector.detect(img_rgb)
        
        img_det = detector.draw_faces(img_rgb, faces)
        
        st.success(f"Đã hoàn thành! Phát hiện **{len(faces)}** khuôn mặt.")
        
        col1, col2 = st.columns(2)
        with col1:
            st.image(img_rgb, caption="Ảnh Gốc", use_container_width=True)
        with col2:
            st.image(img_det, caption="Ảnh Nhận diện (Bounding Box)", use_container_width=True)

with tab2:
    st.header("Chức năng Phân vùng (Face Segmentation)")
    st.write("Sử dụng BiSeNet để tô màu vùng da mặt.")
    upload_seg = st.file_uploader("Tải lên ảnh để Phân vùng", type=["jpg", "jpeg", "png"], key="upload_seg")
    
    if upload_seg is not None:
        image = Image.open(upload_seg).convert('RGB')
        img_rgb = np.array(image)
        
        st.write("Đang xử lý phân vùng...")
        
        # Thực hiện phân vùng TRỰC TIẾP toàn bộ ảnh (không dùng detector để crop)
        mask, _ = segmentor.segment(img_rgb, faces=None)
        
        # Vẽ mask lên ảnh gốc (không giới hạn bounding box)
        img_res = segmentor.draw_segmentation(img_rgb, mask, faces=None)
        
        # Chuẩn bị Bản đồ phân vùng màu
        color_mapped = segmentor.colors[mask]
        parts_map = color_mapped.copy()
        parts_map[mask == 0] = [0, 0, 0] # Background màu đen
        
        # Hiển thị 3 ảnh trên cùng 1 hàng
        st.divider()
        st.header("Kết quả Semantic Segmentation")
        col1, col2, col3 = st.columns(3)
        
        with col1:
            st.image(img_rgb, caption="Ảnh Gốc", use_container_width=True)
        with col2:
            st.image(img_res, caption="Kết quả Overlay", use_container_width=True)
        with col3:
            st.image(parts_map, caption="Bản đồ các bộ phận (Màu)", use_container_width=True)
