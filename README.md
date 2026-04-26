# Hệ thống Nhận diện và Phân vùng Khuôn mặt

Hệ thống cung cấp giải pháp nhận diện khuôn mặt (Face Detection) sử dụng thư viện `RetinaFace` và phân vùng khuôn mặt (Face Segmentation) sử dụng mô hình mạng nơ-ron `BiSeNet`. 

Toàn bộ hệ thống được thiết kế và tối ưu để **chỉ chạy trên CPU**.

## Tính năng
- **Nhận diện khuôn mặt**: Sử dụng thư viện RetinaFace.
- **Phân vùng khuôn mặt**: Sử dụng cấu trúc `BiSeNet` 19 lớp tùy chỉnh, load weights có sẵn `weights/79999_iter.pth`.
- **Giao diện Web**: Giao diện trực quan dùng Streamlit, cho phép tải ảnh lên và xem ngay kết quả.
- **Kiểm thử tự động**: Viết bằng `pytest`, có sẵn script test cho từng phần.

## Cài đặt

1. **Khởi tạo môi trường ảo (khuyến nghị)**:
   ```bash
   python -m venv venv
   source venv/bin/activate  # Trên Linux/Mac
   venv\Scripts\activate     # Trên Windows
   ```

2. **Cài đặt thư viện phụ thuộc**:
   ```bash
   pip install -r requirements.txt
   ```

## Sử dụng

**1. Khởi chạy Giao diện (Streamlit)**:
```bash
streamlit run app.py
```
Giao diện sẽ được mở trên trình duyệt, bạn có thể tải ảnh lên để nhận diện.

**2. Chạy đánh giá trên tập Dataset (WIDER FACE / CelebAMask-HQ)**:
```bash
python scripts/evaluate.py
```
> Lưu ý: Cần đảm bảo dữ liệu đã được đặt tại `data/wider_face/` và `data/celebamask_hq/`.

**3. Chạy Kiểm thử (Tests)**:
```bash
pytest -v tests/
```
Kiểm thử tự động sẽ chạy các mock test và in ra `Pass/Fail` chi tiết.

## Cấu trúc Dự án
- `app.py`: Giao diện ứng dụng Streamlit.
- `models/segmentation/bisenet.py`: Định nghĩa cấu trúc mạng BiSeNet.
- `utils/detector.py`: Bộ xử lý nhận diện khuôn mặt (RetinaFace).
- `utils/segmentor.py`: Bộ xử lý phân vùng khuôn mặt (BiSeNet).
- `scripts/evaluate.py`: Kịch bản đánh giá mô hình.
- `tests/`: Thư mục chứa các file kiểm thử unit test.
- `weights/`: Nơi chứa file `79999_iter.pth`.
