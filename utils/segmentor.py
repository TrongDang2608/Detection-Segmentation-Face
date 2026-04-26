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
        self.model = BiSeNet(n_classes=num_classes)
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
        
        # Bảng màu chuẩn cho 19 lớp CelebAMask-HQ để hiển thị phân biệt rõ từng vùng
        self.colors = np.array([
            [0, 0, 0],         # 0: background (đen)
            [255, 204, 153],   # 1: skin (màu da)
            [0, 255, 255],     # 2: l_brow (lông mày trái - cyan)
            [0, 255, 255],     # 3: r_brow (lông mày phải - cyan)
            [0, 255, 0],       # 4: l_eye (mắt trái - xanh lá)
            [0, 255, 0],       # 5: r_eye (mắt phải - xanh lá)
            [255, 255, 0],     # 6: eye_g (kính - vàng)
            [255, 0, 0],       # 7: l_ear (tai trái - đỏ)
            [255, 0, 0],       # 8: r_ear (tai phải - đỏ)
            [255, 153, 51],    # 9: ear_r (khuyên tai - cam)
            [0, 0, 255],       # 10: nose (mũi - xanh dương)
            [255, 102, 178],   # 11: mouth (miệng - hồng)
            [255, 0, 127],     # 12: u_lip (môi trên - đỏ thắm)
            [255, 0, 127],     # 13: l_lip (môi dưới - đỏ thắm)
            [153, 255, 153],   # 14: neck (cổ - xanh nhạt)
            [102, 0, 204],     # 15: neck_l (vòng cổ - tím)
            [192, 192, 192],   # 16: cloth (áo - xám)
            [102, 51, 0],      # 17: hair (tóc - nâu)
            [255, 255, 255]    # 18: hat (mũ - trắng)
        ], dtype=np.uint8)

    def segment(self, img_rgb, faces=None):
        """
        Phân vùng khuôn mặt trên ảnh.
        Nếu truyền faces, hàm sẽ cắt từng khuôn mặt ra để phân vùng,
        sau đó ghép lại thành mask toàn cục.
        
        Args:
            img_rgb (numpy.ndarray): Ảnh màu RGB (H, W, C).
            faces (list): Danh sách khuôn mặt nhận diện được từ detector.
            
        Returns:
            numpy.ndarray: Ma trận mask toàn cục có kích thước bằng ảnh gốc.
        """
        if faces is None or len(faces) == 0:
            # Fallback: phân vùng toàn ảnh nếu không có detection
            return self._segment_crop(img_rgb), []
            
        global_mask = np.zeros(img_rgb.shape[:2], dtype=np.uint8)
        crops_info = [] # Danh sách lưu các ảnh đã cắt và mask của nó
        
        for face in faces:
            box = face['facial_area']
            x1, y1, x2, y2 = map(int, box)
            
            # Mở rộng bounding box một chút để bao trọn cằm, tóc, trán
            h, w = y2 - y1, x2 - x1
            margin_y = int(h * 0.2)
            margin_x = int(w * 0.2)
            
            x1 = max(0, x1 - margin_x)
            y1 = max(0, y1 - margin_y)
            x2 = min(img_rgb.shape[1], x2 + margin_x)
            y2 = min(img_rgb.shape[0], y2 + margin_y)
            
            crop_img = img_rgb[y1:y2, x1:x2]
            if crop_img.size == 0:
                continue
                
            # Phân vùng trên ảnh crop nhỏ
            crop_mask = self._segment_crop(crop_img)
            
            # Ghi đè crop_mask lên global_mask
            mask_region = global_mask[y1:y2, x1:x2]
            # Chỉ ghi đè những pixel được mô hình nhận diện là face (crop_mask > 0)
            global_mask[y1:y2, x1:x2] = np.where(crop_mask > 0, crop_mask, mask_region)
            
            # Lưu lại thông tin crop để hiển thị lên app
            crops_info.append({
                'crop_img': crop_img,
                'crop_mask': crop_mask
            })
             
        return global_mask, crops_info

    def _segment_crop(self, img_rgb):
        try:
            # Chuyển đổi sang định dạng PIL Image để dùng transform
            img_pil = Image.fromarray(img_rgb)
            w, h = img_pil.size
            
            # Resize ảnh về 512x512
            img_resized = img_pil.resize((512, 512), Image.BILINEAR)
            
            # Chuẩn bị tensor đầu vào
            input_tensor = self.transform(img_resized).unsqueeze(0).to(self.device)
            
            # Suy luận
            with torch.no_grad():
                output = self.model(input_tensor)[0]
                
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

    def draw_segmentation(self, img_rgb, mask, faces=None):
        """
        Vẽ mask phân vùng đè lên ảnh gốc.
        Hiển thị tất cả các bộ phận (tóc, mắt, mũi, miệng...) và loại trừ background.
        Giới hạn trong bounding box (nếu có).
        """
        res_img = img_rgb.copy()
        
        # 1. Tạo mask xác định những vùng CÓ chứa phân vùng (bỏ qua background = 0)
        valid_mask = (mask > 0).astype(np.uint8)
        
        # 2. Giới hạn mask nằm trong vùng bounding box đã nhận diện
        if faces is not None and len(faces) > 0:
            bbox_mask = np.zeros_like(valid_mask)
            for face in faces:
                box = face['facial_area']
                x1, y1, x2, y2 = map(int, box)
                x1, y1 = max(0, x1), max(0, y1)
                x2, y2 = min(img_rgb.shape[1], x2), min(img_rgb.shape[0], y2)
                bbox_mask[y1:y2, x1:x2] = 1
                
            valid_mask = valid_mask * bbox_mask
            
        # 3. Tạo ma trận màu tương ứng với từng id lớp (class id)
        color_mask = self.colors[mask]
        
        # 4. Tìm các pixel hợp lệ để tiến hành alpha blending
        y_indices, x_indices = np.where(valid_mask == 1)
        
        if len(y_indices) > 0:
            # Tăng độ mờ lên 0.5 để nhìn rõ sự phân biệt giữa các bộ phận
            alpha = 0.5 
            
            for c in range(3):
                res_img[y_indices, x_indices, c] = (
                    res_img[y_indices, x_indices, c] * (1 - alpha) + color_mask[y_indices, x_indices, c] * alpha
                )
                
        return res_img
