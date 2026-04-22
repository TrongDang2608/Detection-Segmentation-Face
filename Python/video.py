#Camera.py — ANPR webcam/video: ưu tiên CUDA, live.jpg/MJPEG (tùy chọn), lưu DB & kho ảnh
# pip install ultralytics==8.3.41 opencv-python mysql-connector-python

from ultralytics import YOLO
from pathlib import Path
import argparse, cv2, time, re, numpy as np, os, json, threading, shutil
from datetime import datetime

cv2.setUseOptimized(True)

# ===================== CẤU HÌNH =====================
DEFAULT_WEIGHTS = r"C:\xampp\htdocs\BTVN\Python\runs\detect\train2\weights\best.pt"
DEFAULT_SOURCE  = "0"          # "0" webcam, hoặc đường dẫn .mp4
DEFAULT_MODE    = "webcam"     # image, video, webcam

BASE_DIR    = Path(__file__).resolve().parent         # ...\BTVN\Python
PROJECT_DIR = BASE_DIR.parent                         # ...\BTVN
ROOT_OUT    = PROJECT_DIR / "results_anpr"            # ...\BTVN\results_anpr
ROOT_OUT.mkdir(parents=True, exist_ok=True)

# ===================== Phân lớp YOLO (detection cơ bản) =====================
PLATE_CLASS_ID   = 37     
CONF_THRES_VIDEO = 0.80   # Ngưỡng tin cậy chung khi detect trên video/webcam
PLATE_CONF_MIN   = 0.40   # Ngưỡng riêng cho class biển số: chỉ lấy box có độ tin cậy ≥ 0.40
IMGSZ_VIDEO      = 640    # Kích thước ảnh input khi predict trên video (resize về 640x640)
DEFAULT_SPEED    = 1.0    

# ===================== Cấu hình OCR mềm (nhận dạng ký tự trong biển) =====================
IMGSZ_OCR          = 960      
CONF_PLATE_OCR     = 0.30     # Ngưỡng confidence để chấp nhận box "biển số" khi OCR
CONF_CHAR_OCR      = 0.30     # Ngưỡng confidence để chấp nhận box "ký tự" khi OCR
MIN_PLATE_WH       = (60, 20) # Kích thước tối thiểu (rộng, cao) của ROI biển số, nhỏ hơn thì bỏ qua
PLATE_AR_ONE_LINE  = 2.2      # Ngưỡng tỷ lệ w/h: nếu biển dài hơn 2.2 lần chiều cao thì coi là 1 dòng
Y_SPREAD_K         = 0.60     # Hệ số so sánh độ lệch theo trục Y: giúp tách 1 dòng hay 2 dòng
SPLIT_MODE_DEFAULT = "half"   # Cách chia khi nghi ngờ biển 2 dòng ("half" = chia đôi chiều cao)


# Crop toàn cảnh (khung vàng)
VEH_SCALE_W   = 4.0
VEH_SCALE_H   = 5.5
VEH_PLATE_POS = 0.75
TARGET_ASPECT = 0.90
MIN_CROP_PX   = 400

# Chụp ảnh tĩnh (burst)
BURST_MAX_FRAMES     = 4
BURST_MIN_FRAMES     = 2
BURST_WINDOW_SEC     = 0.30
PEAK_DROP_RATIO      = 0.75
MIN_SHARPNESS_ACCEPT = 12.0  # (hiện không lọc theo ngưỡng, chỉ so sánh tương đối)

# Tránh trùng
DUP_COOLDOWN_SEC      = 8.0
SNAPSHOT_COOLDOWN_SEC = 1.5  # dùng làm default cho --snap_cooldown

# Lưu tất cả snapshot vào DB
SAVE_ALL       = True
DEDUP_ENABLED  = True
FALLBACK_TEXT  = "UNKNOWN"  # không dùng nữa, để nguyên cho tương thích

# live.jpg / MJPEG cho web
LIVE_FPS     = 12
LIVE_QUALITY = 65

# ===================== Thiết bị (GPU/CPU) =====================
try:
    import torch
    TORCH_CUDA = torch.cuda.is_available()
    DEVICE = "cuda:0" if TORCH_CUDA else "cpu"
    FP16 = bool(TORCH_CUDA)
    if TORCH_CUDA:
        torch.backends.cudnn.benchmark = True
except Exception:
    TORCH_CUDA = False
    DEVICE = "cpu"
    FP16 = False

# ===================== DB (lưu ngay) =====================
import mysql.connector

def insert_to_db(so_bien: str, anh_chup: str, anh_toancanh: str = "", id_cam: int = 1):
    try:
        conn = mysql.connector.connect(
            host="localhost",
            user="root",
            password="",
            database="biensoxe",
            autocommit=True,
            connection_timeout=4
        )
        cur = conn.cursor()
        now = datetime.now()
        ngay = now.date().isoformat()
        gio  = now.strftime("%H:%M:%S")

        sql = ("INSERT INTO `bien-so` "
               "(ngay_chup, tg_chup, anh_chup, anh_toancanh, so_bien, province_code, id_cam) "
               "VALUES (%s, %s, %s, %s, %s, %s, %s)")
        cur.execute(sql, (ngay, gio, anh_chup, anh_toancanh, so_bien, None, id_cam))
    except Exception as e:
        print("[DB ERR]", e)
    finally:
        try:
            cur.close(); conn.close()
        except:
            pass

# ===================== HỖ TRỢ =====================
def ts(): return datetime.now().strftime("%Y%m%d_%H%M%S_%f")[:-3]

def rotate_fixed(img, deg):
    if   deg == 90:  return cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
    elif deg == 180: return cv2.rotate(img, cv2.ROTATE_180)
    elif deg == 270: return cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)
    return img

def clamp_in_frame(x1, y1, x2, y2, W, H):
    if x1 < 0:   x2 -= x1; x1 = 0
    if y1 < 0:   y2 -= y1; y1 = 0
    if x2 > W-1: x1 -= (x2-(W-1)); x2 = W-1
    if y2 > H-1: y1 -= (y2-(H-1)); y2 = H-1
    return max(0,int(x1)), max(0,int(y1)), min(W-1,int(x2)), min(H-1,int(y2))

def vehicle_crop_box_portrait(xyxy, W, H, sw=VEH_SCALE_W, sh=VEH_SCALE_H,
                              plate_pos=VEH_PLATE_POS, min_px=MIN_CROP_PX, target_aspect=TARGET_ASPECT):
    x1, y1, x2, y2 = map(float, xyxy)
    cx, cy = (x1+x2)/2, (y1+y2)/2
    bw, bh = (x2-x1), (y2-y1)
    w = max(bw*sw, min_px); h = max(bh*sh, min_px)
    if target_aspect and target_aspect > 0:
        cur = w/h
        if cur > target_aspect: h = w/target_aspect
        else:                   w = h*target_aspect
    ny1 = cy - h*plate_pos; ny2 = ny1 + h
    nx1 = cx - w/2;         nx2 = nx1 + w
    return clamp_in_frame(nx1, ny1, nx2, ny2, W, H)

def expand_box(xyxy, W, H, scale=1.9):
    x1,y1,x2,y2 = map(float, xyxy)
    cx, cy = (x1+x2)/2.0, (y1+y2)/2.0
    w, h = (x2-x1)*scale, (y2-y1)*scale
    nx1, ny1 = cx - w/2.0, cy - h/2.0
    nx2, ny2 = cx + w/2.0, cy + h/2.0
    return clamp_in_frame(nx1, ny1, nx2, ny2, W, H)

def bbox_margin_ok(xyxy, img_w, img_h, rel=0.03, abs_px=6):
    x1,y1,x2,y2 = map(int, xyxy)
    pad = max(int(rel*min(img_w, img_h)), abs_px)
    return (x1 >= pad) and (y1 >= pad) and ((img_w - x2) >= pad) and ((img_h - y2) >= pad)

def sharpness_var_laplacian(img):
    g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    return float(cv2.Laplacian(g, cv2.CV_64F).var())

def draw_label(img, text, x, y, color=(0,255,0)):
    font = cv2.FONT_HERSHEY_SIMPLEX; scale, thick = 0.7, 2
    (tw, th), _ = cv2.getTextSize(text, font, scale, thick)
    y = max(th+6, y)
    cv2.rectangle(img, (x, y-th-8), (x+tw+8, y+4), (0,0,0), -1)
    cv2.putText(img, text, (x+4, y-4), font, scale, color, thick, cv2.LINE_AA)

def to_web_rel(p: Path) -> str:
    """Trả đường dẫn tương đối tính từ PROJECT_DIR (vd: results_anpr/vehicles/veh_*.jpg)"""
    try:
        return p.relative_to(PROJECT_DIR).as_posix()
    except Exception:
        s = p.as_posix().replace('\\', '/')
        pref = PROJECT_DIR.as_posix().rstrip('/') + '/'
        if s.startswith(pref):
            s = s[len(pref):]
        return s

# Bản đồ lớp ký tự
ID2NAME = {0:'-',1:'0',2:'1',3:'2',4:'3',5:'4',6:'5',7:'6',8:'7',9:'8',10:'9',
           11:'A',12:'B',13:'C',14:'D',15:'E',16:'F',17:'G',18:'H',19:'I',20:'J',
           21:'K',22:'L',23:'M',24:'N',25:'O',26:'P',27:'Q',28:'R',29:'S',30:'T',
           31:'U',32:'V',33:'W',34:'X',35:'Y',36:'Z',37:'bien_so'}
CHAR_IDS = {i for i in ID2NAME if i != PLATE_CLASS_ID}

VN_PATTERNS = [
    re.compile(r"^[0-9]{2}[A-Z]-[0-9]{3}\.[0-9]{2}$"),
    re.compile(r"^[0-9]{2}[A-Z]-[0-9]{4,5}$"),
    re.compile(r"^[0-9]{2}-?[A-Z][0-9][0-9]{4,5}$"),
]
CORRECT_MAP = str.maketrans({'O':'0','D':'0','I':'1','L':'1','Z':'2','S':'5','B':'8'})

def correct_text_for_check(s: str) -> str:
    return s.upper().replace(" ", "").replace(".", "").translate(CORRECT_MAP)

def valid_vn_plate(display_text: str) -> bool:
    norm = correct_text_for_check(display_text)
    return any(p.match(norm) for p in VN_PATTERNS)

def inside(inner_xyxy, outer_xyxy, tol=0.01) -> bool:
    x1, y1, x2, y2 = inner_xyxy
    X1, Y1, X2, Y2 = outer_xyxy
    w = X2 - X1; h = Y2 - Y1
    padx = tol * w; pady = tol * h
    return (x1 >= X1 - padx and y1 >= Y1 - pady and x2 <= X2 + padx and y2 <= Y2 + pady)

def split_two_lines(char_boxes_roi, roi_h: int, mode: str = SPLIT_MODE_DEFAULT):
    if not char_boxes_roi:
        return ([], []), False
    ys = [y + h / 2 for (x, y, w, h, cid) in char_boxes_roi]
    thresh = (roi_h * 0.5) if mode == "half" else float(np.median(ys))
    top = [b for b, yc in zip(char_boxes_roi, ys) if yc <= thresh]
    bot = [b for b, yc in zip(char_boxes_roi, ys) if yc > thresh]
    top.sort(key=lambda b: b[0]); bot.sort(key=lambda b: b[0])
    is_two = (len(top) >= 2 and len(bot) >= 2)
    return (top, bot), is_two

def is_one_line_plate(char_boxes_roi, roi_w: int, roi_h: int) -> bool:
    if roi_h == 0 or roi_w == 0 or not char_boxes_roi:
        return False
    ar = roi_w / float(roi_h)
    if ar >= PLATE_AR_ONE_LINE:
        return True
    ys = np.array([y + h / 2 for (x, y, w, h, cid) in char_boxes_roi], float)
    hs = np.array([h for (x, y, w, h, cid) in char_boxes_roi], float)
    if len(ys) < 2:
        return True
    spread = ys.max() - ys.min()
    med_h = np.median(hs) if hs.size else 1.0
    return spread < Y_SPREAD_K * med_h

def read_line(char_boxes):
    return "".join(ID2NAME.get(b[4], "") for b in char_boxes)

def smart_format_plate(text: str) -> str:
    s = text.strip()
    if " " in s:
        parts = s.split()
        first, rest = parts[0], " ".join(parts[1:])
        f = first.replace(" ", "")
        if "-" not in f and len(f) >= 4 and f[:2].isdigit() and f[2].isalpha():
            f = f[:3] + "-" + f[3:]
        return (f + " " + rest).strip()
    s_no = s.replace(" ", "")
    if "-" not in s_no and len(s_no) >= 4 and s_no[:2].isdigit() and s_no[2].isalpha():
        s_no = s_no[:3] + "-" + s_no[3:]
    return s_no

# ===================== MJPEG SERVER =====================
_latest_jpeg = None
_latest_lock = threading.Lock()
_latest_cond = threading.Condition(_latest_lock)

def set_latest_jpeg(jpg_bytes: bytes):
    global _latest_jpeg
    with _latest_cond:
        _latest_jpeg = jpg_bytes
        _latest_cond.notify_all()

def start_mjpeg_server(port: int):
    import socketserver, http.server

    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            if self.path not in ("/", "/stream"):
                self.send_error(404); return
            self.send_response(200)
            self.send_header("Age", "0")
            self.send_header("Cache-Control", "no-cache, private, no-store, must-revalidate")
            self.send_header("Pragma", "no-cache")
            self.send_header("Content-Type", "multipart/x-mixed-replace; boundary=frame")
            self.end_headers()
            try:
                while True:
                    with _latest_cond:
                        _latest_cond.wait(timeout=1.0)
                        frame = _latest_jpeg
                    if frame is None:
                        continue
                    self.wfile.write(b"--frame\r\n")
                    self.wfile.write(b"Content-Type: image/jpeg\r\n")
                    self.wfile.write(b"Content-Length: " + str(len(frame)).encode() + b"\r\n\r\n")
                    self.wfile.write(frame)
                    self.wfile.write(b"\r\n")
            except BrokenPipeError:
                pass
            except Exception:
                pass

        def log_message(self, fmt, *args):
            return  # tắt log

    class ThreadedServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
        daemon_threads = True
        allow_reuse_address = True

    httpd = ThreadedServer(("0.0.0.0", port), Handler)
    th = threading.Thread(target=httpd.serve_forever, daemon=True)
    th.start()
    print(f"[MJPEG] Serving at http://127.0.0.1:{port}/stream")

# ===================== OCR MỀM =====================
def ocr_on_image_soft(model: YOLO, img):
    """
    Trên ảnh tĩnh 'img', trả về:
    (display_text, roi_plate, plate_xyxy_in_img, is_valid_format, num_chars_detected)
    """
    res = model.predict(
        img,
        conf=min(CONF_PLATE_OCR, CONF_CHAR_OCR),
        imgsz=IMGSZ_OCR,
        device=DEVICE,
        half=FP16,
        verbose=False
    )[0]

    boxes = res.boxes.xyxy.cpu().numpy() if res.boxes is not None else np.zeros((0,4))
    clss  = res.boxes.cls.cpu().numpy().astype(int) if res.boxes is not None else np.zeros((0,), int)
    confs = res.boxes.conf.cpu().numpy() if res.boxes is not None else np.zeros((0,))

    plate_idx = [i for i,(c,cf) in enumerate(zip(clss,confs)) if c == PLATE_CLASS_ID and cf >= CONF_PLATE_OCR]
    char_idx  = [i for i,(c,cf) in enumerate(zip(clss,confs)) if c in CHAR_IDS and cf >= CONF_CHAR_OCR]
    if not plate_idx:
        return "", None, None, False, 0

    plate_idx.sort(key=lambda i: (boxes[i][2]-boxes[i][0])*(boxes[i][3]-boxes[i][1]), reverse=True)
    pi = plate_idx[0]
    x1, y1, x2, y2 = boxes[pi].astype(int).tolist()
    if (x2-x1) < MIN_PLATE_WH[0] or (y2-y1) < MIN_PLATE_WH[1]:
        return "", None, (x1, y1, x2, y2), False, 0

    roi = img[y1:y2, x1:x2]
    roi_h, roi_w = roi.shape[:2]

    chars_roi = []
    for ci in char_idx:
        cx1, cy1, cx2, cy2 = boxes[ci]
        if inside((cx1, cy1, cx2, cy2), (x1, y1, x2, y2), tol=0.01):
            chars_roi.append((int(cx1-x1), int(cy1-y1), int(cx2-cx1), int(cy2-cy1), int(clss[ci])))

    num_chars = len(chars_roi)

    if is_one_line_plate(chars_roi, roi_w, roi_h):
        one_line_sorted = sorted(chars_roi, key=lambda b: b[0])
        display_text = read_line(one_line_sorted)
    else:
        (top, bot), is_two = split_two_lines(chars_roi, roi_h, mode=SPLIT_MODE_DEFAULT)
        if not is_two:
            (top, bot), is_two = split_two_lines(chars_roi, roi_h, mode="median")
        top_text = read_line(top)
        bot_text = read_line(bot) if is_two else ""
        display_text = (top_text + (" " + bot_text if bot_text else "")).strip()

    display_text = smart_format_plate(display_text)
    is_valid = valid_vn_plate(display_text)
    return display_text, roi, (x1, y1, x2, y2), is_valid, num_chars

# ===================== VIDEO/WEBCAM MODE =====================
def open_cam_with_fallback(cam_index=0, w=1280, h=720):
    for backend in (cv2.CAP_DSHOW, cv2.CAP_MSMF, cv2.CAP_ANY):
        try:
            cap = cv2.VideoCapture(int(cam_index), backend)
            if cap.isOpened():
                try:
                    cap.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc(*'MJPG'))
                    cap.set(cv2.CAP_PROP_FRAME_WIDTH,  w)
                    cap.set(cv2.CAP_PROP_FRAME_HEIGHT, h)
                    cap.set(cv2.CAP_PROP_BUFFERSIZE,   1)
                except Exception:
                    pass
                print("[OK] Webcam backend:",
                      "DSHOW" if backend==cv2.CAP_DSHOW else ("MSMF" if backend==cv2.CAP_MSMF else "ANY"))
                return cap
        except Exception:
            pass
    return None

def run_stream_mode(model: YOLO, source: str, out_dir: Path, show: bool, rotate: int,
                    speed: float, plate_conf: float, cam_index: int,
                    no_overlay: bool, no_live: bool, mjpeg_port: int,
                    snap_cooldown: float, min_streak: int):

    # out_dir có thể là results_anpr/add_staging
    out_dir.mkdir(parents=True, exist_ok=True)
    veh_dir   = out_dir / "vehicles"; veh_dir.mkdir(parents=True, exist_ok=True)
    plate_dir = out_dir / "crops";    plate_dir.mkdir(parents=True, exist_ok=True)
    live_jpg = out_dir / "live.jpg"
    live_tmp = out_dir / "live.tmp.jpg"
    stop_flag    = out_dir / "STOP.flag"
    running_flag = out_dir / "RUNNING.flag"

    # Kho chính (để web/lịch sử đọc): nếu đang ở add_staging thì main_dir = parent
    main_dir = out_dir.parent if out_dir.name.lower() == "add_staging" else out_dir
    (main_dir / "vehicles").mkdir(parents=True, exist_ok=True)
    (main_dir / "crops").mkdir(parents=True, exist_ok=True)

    # mở nguồn
    if str(source).isdigit():
        cap = open_cam_with_fallback(int(source), 1280, 720)
    else:
        cap = cv2.VideoCapture(source)

    if not cap or not cap.isOpened():
        raise RuntimeError(f"Không mở được source: {source}")

    # Lập lịch tốc độ phát cho FILE video
    if not str(source).isdigit():
        src_fps = cap.get(cv2.CAP_PROP_FPS)
        if not src_fps or np.isnan(src_fps) or src_fps <= 0:
            src_fps = 25.0
        speed_rate     = max(1e-4, float(speed))
        target_interval= (1.0 / src_fps) / speed_rate
        next_ts = time.perf_counter()
    else:
        target_interval = None
        next_ts = None

    burst_imgs = []
    burst_start_ts   = 0.0
    snapshot_last_ts = 0.0
    last_saved_text_ts = {}

    # live throttle
    next_live_t = 0.0
    det_streak = 0

    while True:
        if stop_flag.exists():
            try: stop_flag.unlink()
            except: pass
            break

        ok, frame = cap.read()
        if not ok:
            time.sleep(0.03)
            continue

        if rotate: frame = rotate_fixed(frame, rotate)
        H, W = frame.shape[:2]

        rs = model.predict(
            frame,
            conf=CONF_THRES_VIDEO,
            imgsz=IMGSZ_VIDEO,
            device=DEVICE,
            half=FP16,
            verbose=False
        )[0]

        plate_boxes=[]
        if rs.boxes is not None and len(rs.boxes)>0:
            cls = rs.boxes.cls.cpu().numpy().astype(int)
            xyxy= rs.boxes.xyxy.cpu().numpy()
            conf= rs.boxes.conf.cpu().numpy()
            for i in range(len(cls)):
                if cls[i]==PLATE_CLASS_ID and conf[i]>=plate_conf:
                    plate_boxes.append((xyxy[i], conf[i]))

        # cập nhật streak theo phát hiện
        if plate_boxes:
            det_streak += 1
        else:
            det_streak = 0

        disp = frame if (no_overlay and mjpeg_port>0 and no_live) else frame.copy()

        if plate_boxes:
            plate_boxes.sort(key=lambda t:(t[0][2]-t[0][0])*(t[0][3]-t[0][1]), reverse=True)
            best_xyxy, best_conf = plate_boxes[0]

            x1,y1,x2,y2 = map(int, best_xyxy)
            if not no_overlay:
                try:
                    cv2.rectangle(disp,(x1,y1),(x2,y2),(0,255,0),2)
                    draw_label(disp, f"bien_so {best_conf:.2f}", x1, max(0,y1-6), (0,255,0))
                    plate_h = best_xyxy[3] - best_xyxy[1]
                    near_ratio = plate_h / float(H)
                    sw, sh = VEH_SCALE_W, VEH_SCALE_H
                    if near_ratio > 0.28: sw, sh = 3.2, 4.0
                    vx1,vy1,vx2,vy2 = vehicle_crop_box_portrait(best_xyxy, W, H, sw, sh, VEH_PLATE_POS, MIN_CROP_PX, TARGET_ASPECT)
                    cv2.rectangle(disp,(vx1,vy1),(vx2,vy2),(0,255,255),2)
                except Exception:
                    pass

            # Ảnh toàn cảnh cho burst/snapshot
            plate_h = best_xyxy[3] - best_xyxy[1]
            near_ratio = plate_h / float(H)
            sw, sh = VEH_SCALE_W, VEH_SCALE_H
            if near_ratio > 0.28: sw, sh = 3.2, 4.0
            vx1,vy1,vx2,vy2 = vehicle_crop_box_portrait(best_xyxy, W, H, sw, sh, VEH_PLATE_POS, MIN_CROP_PX, TARGET_ASPECT)
            veh_img_live = frame[vy1:vy2, vx1:vx2]

            now_mono = time.monotonic()
            # chỉ chụp khi đủ streak + đủ cooldown
            if det_streak >= min_streak and (now_mono - snapshot_last_ts) >= snap_cooldown:
                sharp = sharpness_var_laplacian(veh_img_live)
                if not burst_imgs:
                    burst_start_ts = now_mono
                burst_imgs.append((veh_img_live.copy(), sharp, now_mono))
                if len(burst_imgs) > BURST_MAX_FRAMES:
                    burst_imgs.pop(0)

                should_close = False
                if len(burst_imgs) >= BURST_MIN_FRAMES and (now_mono - burst_start_ts) >= BURST_WINDOW_SEC:
                    should_close = True
                else:
                    sharps = [s for _,s,_ in burst_imgs]
                    if len(sharps) >= 2 and sharps[-1] < PEAK_DROP_RATIO*max(sharps):
                        should_close = True

                if should_close:
                    snapshot_last_ts = now_mono
                    best_img, _, _ = max(burst_imgs, key=lambda t:t[1])
                    burst_imgs = []

                    # nhẹ tay tăng nét
                    blur = cv2.GaussianBlur(best_img, (0,0), 1.0)
                    still_img = cv2.addWeighted(best_img, 1.5, blur, -0.5, 0)

                    # Lưu ảnh toàn cảnh tại out_dir trước (an toàn)
                    tag = ts()
                    veh_path_out   = veh_dir  / f"veh_{tag}.jpg"
                    plate_path_out = plate_dir / f"plate_{tag}.png"
                    cv2.imwrite(str(veh_path_out), still_img)

                    # OCR trên tĩnh
                    text, roi, plate_xyxy, is_valid, num_chars = ocr_on_image_soft(model, still_img)

                    # retry recrop nếu ROI chưa ok
                    full_ok = (plate_xyxy is not None) and bbox_margin_ok(plate_xyxy, still_img.shape[1], still_img.shape[0], rel=0.06, abs_px=10)
                    need_retry = (not full_ok) or (num_chars < 7)
                    if need_retry:
                        ex1,ey1,ex2,ey2 = expand_box(best_xyxy, W, H, scale=1.9)
                        plate_focus = frame[ey1:ey2, ex1:ex2]
                        if plate_focus.size > 0:
                            text2, roi2, plate_xyxy2, is_valid2, num_chars2 = ocr_on_image_soft(model, plate_focus)
                            ok2 = (plate_xyxy2 is not None) and bbox_margin_ok(plate_xyxy2, plate_focus.shape[1], plate_focus.shape[0], rel=0.06, abs_px=10) and num_chars2 >= 1
                            if ok2:
                                text, roi, plate_xyxy, is_valid, num_chars = text2, roi2, plate_xyxy2, is_valid2, num_chars2
                                still_img = plate_focus

                    # Lưu ROI nếu có, nếu không dùng ảnh toàn cảnh
                    plate_img_for_db = None
                    try:
                        if roi is not None and roi.size > 0:
                            cv2.imwrite(str(plate_path_out), roi)
                            plate_img_for_db = plate_path_out
                        else:
                            plate_img_for_db = veh_path_out
                    except Exception:
                        plate_img_for_db = veh_path_out

                    # ---- CHUẨN HÓA TEXT & BỎ TRỐNG + LỌC ĐỘ DÀI ----
                    disp_text = (text or "").strip()

                    # KHÔNG có text -> bỏ qua
                    if not disp_text:
                        # KHÔNG có biển số => KHÔNG copy sang kho chính, KHÔNG ghi log/DB
                        continue

                    # Loại biển số quá ngắn: chỉ tính A-Z/0-9 sau chuẩn hoá
                    _norm = re.sub(r'[^A-Z0-9]', '', correct_text_for_check(disp_text))
                    if len(_norm) < 5:
                        # < 5 ký tự hữu ích => bỏ qua hoàn toàn
                        continue

                    # ---- CHỈ KHI CÓ TEXT HỢP LỆ VỀ ĐỘ DÀI MỚI COPY SANG KHO CHÍNH ----
                    veh_ext   = Path(veh_path_out).suffix or ".jpg"
                    plate_ext = Path(plate_img_for_db).suffix or ".png"
                    main_veh_path   = main_dir / "vehicles" / f"veh_{tag}{veh_ext}"
                    main_plate_path = main_dir / "crops"    / f"plate_{tag}{plate_ext}"

                    try: shutil.copy2(veh_path_out, main_veh_path)
                    except Exception: main_veh_path = Path(veh_path_out)
                    try: shutil.copy2(plate_img_for_db, main_plate_path)
                    except Exception: main_plate_path = Path(plate_img_for_db)

                    # ---- PATH WEB + GHI plates.txt ----
                    web_veh   = to_web_rel(main_veh_path)
                    web_plate = to_web_rel(main_plate_path)

                    for log_dir in {out_dir, main_dir}:
                        try:
                            with open(log_dir / "plates.txt", "a", encoding="utf-8") as f:
                                f.write(f"{datetime.now():%Y-%m-%d %H:%M:%S}\t{disp_text}\t{web_veh}\t{web_plate}\n")
                        except Exception:
                            pass

                    # ---- LƯU DB (có chống trùng theo text) ----
                    can_save = True
                    if DEDUP_ENABLED:
                        last_ts = last_saved_text_ts.get(disp_text, 0.0)
                        can_save = (time.monotonic() - last_ts) >= DUP_COOLDOWN_SEC

                    if SAVE_ALL and can_save:     # <- dùng and, KHÔNG phải &&
                        print(f"[PLATE] {disp_text}")
                        insert_to_db(disp_text, web_plate, web_veh, id_cam=cam_index)
                        if DEDUP_ENABLED:
                            last_saved_text_ts[disp_text] = time.monotonic()

        # ----- Xuất preview: live.jpg và/hoặc MJPEG -----
        now_t = time.monotonic()
        if now_t >= next_live_t:
            try:
                h, w = disp.shape[:2]
                target_w = 960
                if w > target_w:
                    target_h = int(h * (target_w / float(w)))
                    disp_out = cv2.resize(disp, (target_w, target_h), interpolation=cv2.INTER_AREA)
                else:
                    disp_out = disp

                ok_enc, enc = cv2.imencode(".jpg", disp_out, [int(cv2.IMWRITE_JPEG_QUALITY), LIVE_QUALITY])
                if ok_enc:
                    jpg_bytes = enc.tobytes()
                    if mjpeg_port > 0:
                        set_latest_jpeg(jpg_bytes)
                    if not no_live:
                        try:
                            with open(live_tmp, "wb") as f:
                                f.write(jpg_bytes)
                            os.replace(live_tmp, live_jpg)
                        except Exception:
                            pass
            except Exception:
                pass
            next_live_t = now_t + 1.0 / max(1, LIVE_FPS)

        # ===== Hãm tốc playback nếu là FILE video =====
        if target_interval is not None:
            next_ts += target_interval
            remain = next_ts - time.perf_counter()
            if remain > 0:
                time.sleep(remain)
            else:
                next_ts = time.perf_counter()

        # === PREVIEW UI KHI DÙNG --show (cửa sổ OpenCV) ===
        if show:
            try:
                if not hasattr(run_stream_mode, "_win_inited"):
                    cv2.namedWindow("ANPR", cv2.WINDOW_NORMAL)
                    cv2.resizeWindow("ANPR", 960, 540)
                    run_stream_mode._win_inited = True
                cv2.imshow("ANPR", disp)
                key = cv2.waitKey(1) & 0xFF
                if key == ord('q') or key == 27:
                    break
            except Exception:
                pass

    cap.release()

    # cleanup flags
    try:
        if running_flag.exists():
            running_flag.unlink()
        if stop_flag.exists():
            stop_flag.unlink()
    except:
        pass

    if show:
        try: cv2.destroyAllWindows()
        except: pass

# ===================== MAIN =====================
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--mode',   type=str, choices=['image','video','webcam'], default=DEFAULT_MODE)
    ap.add_argument('--weights',type=str, default=DEFAULT_WEIGHTS)
    ap.add_argument('--source', type=str, default=str(DEFAULT_SOURCE))
    # Gợi ý: nếu PHP chạy trong add_staging, truyền --out=.../results_anpr/add_staging
    ap.add_argument('--out',    type=str, default=str(ROOT_OUT))
    ap.add_argument('--plate_conf', type=float, default=PLATE_CONF_MIN)
    ap.add_argument('--show',   action='store_true', default=False)
    ap.add_argument('--speed',  type=float, default=DEFAULT_SPEED)
    ap.add_argument('--rotate', type=int, choices=[0,90,180,270], default=0)
    ap.add_argument('--cam',    type=int, default=1)

    # Stream options
    ap.add_argument('--no_overlay', action='store_true', default=False, help='Không vẽ khung/label lên preview')
    ap.add_argument('--no_live',    action='store_true', default=False, help='Không ghi live.jpg')
    ap.add_argument('--mjpeg_port', type=int, default=0, help='Bật MJPEG HTTP server ở port này (0 = tắt)')

    # Tham số điều khiển tần suất chụp
    ap.add_argument('--snap_cooldown', type=float, default=SNAPSHOT_COOLDOWN_SEC,
                    help='Giãn cách tối thiểu giữa 2 lần chụp (giây)')
    ap.add_argument('--min_streak', type=int, default=3,
                    help='Số frame liên tiếp thấy biển mới cho phép chụp')

    args = ap.parse_args()

    out_dir = Path(args.out); out_dir.mkdir(parents=True, exist_ok=True)

    # GHI RUNNING.FLAG SỚM
    try:
        (out_dir / "RUNNING.flag").write_text(
            json.dumps({"pid": os.getpid(), "cam": args.cam}, ensure_ascii=False), encoding="utf-8"
        )
    except Exception:
        pass

    print(f"[INFO] device={DEVICE} fp16={FP16} mode={args.mode} source={args.source}")
    if args.mjpeg_port and args.mjpeg_port > 0:
        start_mjpeg_server(args.mjpeg_port)

    model = YOLO(args.weights)
    try: model.to(DEVICE)
    except Exception: pass

    # warmup giảm khựng frame đầu
    _dummy = np.zeros((IMGSZ_VIDEO, IMGSZ_VIDEO, 3), dtype=np.uint8)
    model.predict(_dummy, imgsz=IMGSZ_VIDEO, conf=0.25, device=DEVICE, half=FP16, verbose=False)

    if args.mode in ('video','webcam'):
        speed = 1.0 if args.mode == 'webcam' else args.speed
        run_stream_mode(model, args.source, out_dir, args.show, args.rotate, speed,
                        args.plate_conf, args.cam, args.no_overlay, args.no_live, args.mjpeg_port,
                        args.snap_cooldown, args.min_streak)
    else:
        print("Mode 'image' không dùng trong quy trình này.")

if __name__ == "__main__":
    main()
