import cv2
import numpy as np
from retinaface import RetinaFace
import time

class FaceDetector:
    """
    FaceDetector Phiên bản Tối ưu HARD Mode (Tiling).
    Cân bằng giữa Recall cao (bắt mặt nhỏ) và Precision (giảm False Positive).
    """
    def __init__(self, threshold=0.3):
        self.threshold = threshold

    def _preprocess(self, img_rgb, gamma=1.1):
        """Xử lý ảnh nhẹ nhàng để tăng độ tương phản vùng tối."""
        if gamma != 1.0:
            invGamma = 1.0 / gamma
            table = np.array([((i / 255.0) ** invGamma) * 255 for i in np.arange(0, 256)]).astype("uint8")
            img_rgb = cv2.LUT(img_rgb, table)
        return img_rgb

    def detect(self, img_rgb, upscale_factor=1.5, conf_thresh=0.3, nms_thresh=0.4):
        """Chế độ nhận diện cơ bản (Single-scale)."""
        img_prep = self._preprocess(img_rgb)
        h, w = img_rgb.shape[:2]
        img_input = cv2.resize(img_prep, (int(w * upscale_factor), int(h * upscale_factor)))
        
        faces_dict = RetinaFace.detect_faces(img_input, threshold=conf_thresh)
        all_detections = []
        if isinstance(faces_dict, dict):
            for face in faces_dict.values():
                box = [int(b / upscale_factor) for b in face['facial_area']]
                all_detections.append({'score': face['score'], 'facial_area': box})
        
        return self._nms(all_detections, nms_thresh=nms_thresh)

    def detect_hard_mode(self, img_rgb, grid_size=(4, 4), overlap=0.2):
        """
        Chế độ HARD (Tiling): Chia ảnh thành lưới để bắt mặt siêu nhỏ.
        Đã cải tiến để giảm False Positives (nhận diện nhầm).
        """
        start_time = time.time()
        h, w = img_rgb.shape[:2]
        rows, cols = grid_size
        tile_h, tile_w = h // rows, w // cols
        
        all_detections = []
        
        # Tiền xử lý toàn bộ ảnh trước khi cắt
        img_prep = self._preprocess(img_rgb, gamma=1.1)
        
        for r in range(rows):
            for c in range(cols):
                y1 = max(0, r * tile_h - int(tile_h * overlap))
                y2 = min(h, (r + 1) * tile_h + int(tile_h * overlap))
                x1 = max(0, c * tile_w - int(tile_w * overlap))
                x2 = min(w, (c + 1) * tile_w + int(tile_w * overlap))
                
                tile = img_prep[y1:y2, x1:x2]
                
                # Upscale ô lên 2.0x (điểm cân bằng giữa 1.5x và 3.0x)
                upscale = 2.0
                tile_res = cv2.resize(tile, (0,0), fx=upscale, fy=upscale)
                
                # Tăng nhẹ threshold lên 0.3 để giảm False Positive
                faces_dict = RetinaFace.detect_faces(tile_res, threshold=0.3)
                
                if isinstance(faces_dict, dict):
                    for face in faces_dict.values():
                        score = face['score']
                        bx1, by1, bx2, by2 = [b / upscale for b in face['facial_area']]
                        
                        # 1. Lọc theo hình học (Aspect Ratio) - Face thường cao hơn rộng một chút
                        fw = bx2 - bx1
                        fh = by2 - by1
                        if fw == 0 or fh == 0: continue
                        ratio = fh / fw
                        
                        # 2. Loại bỏ các box quá dẹt hoặc quá dài (nhiễu biên)
                        if 0.5 < ratio < 2.2:
                            original_box = [int(bx1 + x1), int(by1 + y1), int(bx2 + x1), int(by2 + y1)]
                            all_detections.append({'score': score, 'facial_area': original_box})

        # 3. Sử dụng NMS 0.5 (nghiêm ngặt hơn 0.7) để lọc các box ảo chồng lấn
        final_faces = self._nms(all_detections, nms_thresh=0.5)
        
        print(f"HARD Mode Refined: Found {len(final_faces)} faces in {time.time()-start_time:.2f}s")
        return final_faces

    def _nms(self, detections, nms_thresh):
        if not detections: return []
        boxes = np.array([d['facial_area'] for d in detections])
        scores = np.array([d['score'] for d in detections])
        x1, y1, x2, y2 = boxes[:, 0], boxes[:, 1], boxes[:, 2], boxes[:, 3]
        areas = (x2 - x1 + 1) * (y2 - y1 + 1)
        order = scores.argsort()[::-1]
        keep = []
        while order.size > 0:
            i = order[0]
            keep.append(detections[i])
            xx1, yy1 = np.maximum(x1[i], x1[order[1:]]), np.maximum(y1[i], y1[order[1:]])
            xx2, yy2 = np.maximum(y1[i], y1[order[1:]]), np.maximum(y1[i], y1[order[1:]]) # Lỗi typo ở turn trước, sửa lại
            xx2, yy2 = np.minimum(x2[i], x2[order[1:]]), np.minimum(y2[i], y2[order[1:]])
            w, h = np.maximum(0.0, xx2 - xx1 + 1), np.maximum(0.0, yy2 - yy1 + 1)
            ovr = (w * h) / (areas[i] + areas[order[1:]] - (w * h))
            order = order[np.where(ovr <= nms_thresh)[0] + 1]
        return keep

    def draw_faces(self, img_rgb, faces, color=(0, 255, 0)):
        res_img = img_rgb.copy()
        for face in faces:
            box = face['facial_area']
            cv2.rectangle(res_img, (box[0], box[1]), (box[2], box[3]), color, 1)
        return res_img
