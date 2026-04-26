import torch
import torchvision.transforms as transforms
import numpy as np
from PIL import Image
import cv2
from models.segmentation.bisenet import BiSeNet

class FaceSegmentor:
    """
    Lớp FaceSegmentor sử dụng mô hình BiSeNet để phân vùng khuôn mặt.
    Sử dụng CPU để suy luận (Inference).
    """
    def __init__(self, weight_path='weights/79999_iter.pth', num_classes=19):
        self.device = torch.device('cpu')
        
        # Khởi tạo mô hình
        self.model = BiSeNet(num_classes=num_classes)
        self.model.to(self.device)
        self.model.eval() # Chế độ đánh giá (không huấn luyện)
        
        # Tải trọng số mô hình (weights) lên CPU
        try:
            # Do file weights có thể được huấn luyện từ DataParallel hoặc cấu trúc khác
            # Ở đây giả định file state_dict là tiêu chuẩn. Nếu có tiền tố 'module.', cần lược bỏ.
            state_dict = torch.load(weight_path, map_location=self.device)
            if 'state_dict' in state_dict:
                state_dict = state_dict['state_dict']
            
            # Xử lý trường hợp có tiền tố module (từ nn.DataParallel)
            new_state_dict = {}
            for k, v in state_dict.items():
                new_key = k.replace('module.', '') if k.startswith('module.') else k
                new_state_dict[new_key] = v
                
            self.model.load_state_dict(new_state_dict, strict=False)
            print(f"Đã tải thành công weights từ {weight_path}")
        except Exception as e:
            print(f"Lỗi khi tải weights BiSeNet: {e}")
            
        # Các bước tiền xử lý ảnh cho BiSeNet
        self.transform = transforms.Compose([
            transforms.ToTensor(),
            transforms.Normalize(mean=[0.485, 0.456, 0.406], std=[0.229, 0.224, 0.225])
        ])
        
        # Bảng màu cho 19 lớp (dùng để vẽ kết quả)
        self.colors = np.random.randint(0, 255, (num_classes, 3), dtype=np.uint8)
        self.colors[0] = [0, 0, 0] # Background là màu đen

    def segment(self, img_rgb):
        """
        Phân vùng khuôn mặt trên ảnh.
        
        Args:
            img_rgb (numpy.ndarray): Ảnh màu RGB (H, W, C).
            
        Returns:
            numpy.ndarray: Ma trận mask với các giá trị từ 0 đến num_classes-1.
        """
        try:
            # Chuyển đổi sang định dạng PIL Image để dùng transform
            img_pil = Image.fromarray(img_rgb)
            w, h = img_pil.size
            
            # Khuyến nghị resize ảnh nhỏ lại để xử lý nhanh hơn trên CPU nếu ảnh quá lớn
            # Ví dụ: resize về 512x512
            img_resized = img_pil.resize((512, 512), Image.BILINEAR)
            
            # Chuẩn bị tensor đầu vào
            input_tensor = self.transform(img_resized).unsqueeze(0).to(self.device)
            
            # Suy luận
            with torch.no_grad():
                output = self.model(input_tensor)
                
            # Lấy chỉ số lớp có xác suất cao nhất tại mỗi pixel
            mask = output.squeeze(0).cpu().numpy() # Shape: (C, H, W)
            mask = np.argmax(mask, axis=0) # Shape: (H, W)
            
            # Resize mask về kích thước ảnh gốc
            mask_pil = Image.fromarray(mask.astype(np.uint8))
            mask_resized = mask_pil.resize((w, h), Image.NEAREST)
            
            return np.array(mask_resized)
        except Exception as e:
            print(f"Lỗi trong quá trình phân vùng: {e}")
            return np.zeros(img_rgb.shape[:2], dtype=np.uint8)

    def draw_segmentation(self, img_rgb, mask):
        """
        Vẽ mask phân vùng đè lên ảnh gốc.
        
        Args:
            img_rgb (numpy.ndarray): Ảnh gốc.
            mask (numpy.ndarray): Ma trận phân vùng.
            
        Returns:
            numpy.ndarray: Ảnh đã được vẽ mask đè lên.
        """
        # Tạo ảnh mask có màu
        color_mask = self.colors[mask]
        
        # Kết hợp ảnh gốc và mask với độ mờ (alpha blending)
        alpha = 0.5
        res_img = cv2.addWeighted(img_rgb, 1 - alpha, color_mask, alpha, 0)
        return res_img
