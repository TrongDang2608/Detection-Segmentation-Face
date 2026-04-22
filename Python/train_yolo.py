from ultralytics import YOLO

def train():
    # Load pre-trained YOLOv8s
    model = YOLO("yolov8s.pt")

    # Train
    model.train(
        data="mydata.yaml",   # file cấu hình dataset
        epochs=50,           # số epoch
        batch=6,              # batch nhỏ để không tràn GPU
        imgsz=640,            # kích thước ảnh (giữ chuẩn YOLO)
        device=0,             # GPU 0
        workers=0,            # tránh lỗi DataLoader với Windows
        patience=10            # không dừng sớm
    )
if __name__ == "__main__":
    train()
