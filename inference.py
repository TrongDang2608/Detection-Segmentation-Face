import cv2
import torch
import numpy as np
import argparse
import os
from retinaface import RetinaFace
import segmentation_models_pytorch as smp
import albumentations as albu

def get_unet_model(weights_path=None, device="cpu"):
    """Khởi tạo mô hình U-Net."""
    # Khởi tạo kiến trúc U-Net với ResNet50 backbone (rất phổ biến cho face segmentation)
    model = smp.Unet(
        encoder_name="resnet50",
        encoder_weights="imagenet" if weights_path is None else None,
        in_channels=3,
        classes=1, # 1 class cho face mask
        activation='sigmoid'
    )
    
    if weights_path and os.path.exists(weights_path):
        print(f"Loading weights từ {weights_path}...")
        model.load_state_dict(torch.load(weights_path, map_location=device))
    else:
        print("CẢNH BÁO: Không tìm thấy file weights U-Net cho Face mask. Đang dùng weights ImageNet ngẫu nhiên, kết quả segmentation sẽ không chính xác!")
        
    model.to(device)
    model.eval()
    return model

def process_face(img_bgr, face_box, unet_model, device):
    """Cắt khuôn mặt, đưa qua U-Net lấy mask, rồi dán lại mask vào ảnh gốc."""
    x1, y1, x2, y2 = face_box
    # Cắt xén một chút rộng hơn khuôn mặt
    h, w = img_bgr.shape[:2]
    padding = int((y2 - y1) * 0.2)
    x1 = max(0, x1 - padding)
    y1 = max(0, y1 - padding)
    x2 = min(w, x2 + padding)
    y2 = min(h, y2 + padding)
    
    face_crop = img_bgr[y1:y2, x1:x2]
    if face_crop.size == 0:
        return img_bgr
        
    # Chuẩn bị ảnh cho U-Net
    face_rgb = cv2.cvtColor(face_crop, cv2.COLOR_BGR2RGB)
    resized_face = cv2.resize(face_rgb, (256, 256))
    
    # Normalize theo chuẩn ImageNet
    mean = [0.485, 0.456, 0.406]
    std = [0.229, 0.224, 0.225]
    tensor_img = (resized_face / 255.0 - mean) / std
    tensor_img = np.transpose(tensor_img, (2, 0, 1)).astype(np.float32)
    tensor_img = torch.tensor(tensor_img).unsqueeze(0).to(device)
    
    # Inference Segmentation
    with torch.no_grad():
        pred_mask = unet_model(tensor_img)
        pred_mask = pred_mask.squeeze().cpu().numpy()
        
    # Resize mask về kích thước gốc của khuôn mặt
    pred_mask = cv2.resize(pred_mask, (face_crop.shape[1], face_crop.shape[0]))
    
    # Tạo màu Overlay (vd: màu xanh lá cây cho mask)
    mask_colored = np.zeros_like(face_crop)
    mask_colored[:, :, 1] = 255 # Green channel
    
    # Trộn mask vào ảnh khuôn mặt cắt ra
    alpha = 0.5
    binary_mask = (pred_mask > 0.5).astype(np.uint8)
    
    for c in range(3):
        face_crop[:, :, c] = np.where(binary_mask == 1,
                                      face_crop[:, :, c] * (1 - alpha) + mask_colored[:, :, c] * alpha,
                                      face_crop[:, :, c])
                                      
    img_bgr[y1:y2, x1:x2] = face_crop
    
    # Vẽ Box RetinaFace
    cv2.rectangle(img_bgr, (x1, y1), (x2, y2), (255, 0, 0), 2)
    
    return img_bgr

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--image", type=str, required=True, help="Path to input image")
    parser.add_argument("--output", type=str, default="data/output_images/result.jpg", help="Path to save output image")
    parser.add_argument("--unet_weights", type=str, default="weights/unet_face.pth", help="Path to U-Net pre-trained weights")
    args = parser.parse_args()

    device = "cuda" if torch.cuda.is_available() else "cpu"
    print(f"Using device: {device}")

    # Khởi tạo mô hình Segmentation (U-Net)
    unet_model = get_unet_model(args.unet_weights, device)

    # Đọc ảnh gốc
    print(f"Loading image {args.image}...")
    img = cv2.imread(args.image)
    if img is None:
        raise ValueError("Image not found!")

    # 1. Detection bằng RetinaFace
    print("Running RetinaFace Detection...")
    # RetinaFace trả về dictionary: {"face_1": {"score": 0.99, "facial_area": [x1, y1, x2, y2], ...}, ...}
    resp = RetinaFace.detect_faces(args.image)
    
    if type(resp) is dict:
        print(f"Detected {len(resp)} faces.")
        for key in resp.keys():
            identity = resp[key]
            facial_area = identity["facial_area"]
            
            # 2. Segmentation từng khuôn mặt bằng U-Net
            img = process_face(img, facial_area, unet_model, device)
    else:
        print("No faces detected.")

    # Lưu kết quả
    cv2.imwrite(args.output, img)
    print(f"Done! Result saved to {args.output}")

if __name__ == "__main__":
    main()
