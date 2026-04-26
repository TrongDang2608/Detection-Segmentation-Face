import torch
import torch.nn as nn
import torch.nn.functional as F

# Định nghĩa khối ConvBlock cơ bản
class ConvBlock(nn.Module):
    def __init__(self, in_channels, out_channels, kernel_size=3, stride=2, padding=1):
        super(ConvBlock, self).__init__()
        self.conv = nn.Conv2d(in_channels, out_channels, kernel_size=kernel_size, stride=stride, padding=padding, bias=False)
        self.bn = nn.BatchNorm2d(out_channels)
        self.relu = nn.ReLU(inplace=True)

    def forward(self, x):
        return self.relu(self.bn(self.conv(x)))

# Spatial Path (Đường dẫn Không gian) - Giữ lại độ phân giải cao
class SpatialPath(nn.Module):
    def __init__(self):
        super(SpatialPath, self).__init__()
        self.conv1 = ConvBlock(3, 64, kernel_size=7, stride=2, padding=3)
        self.conv2 = ConvBlock(64, 64, kernel_size=3, stride=2, padding=1)
        self.conv3 = ConvBlock(64, 64, kernel_size=3, stride=2, padding=1)

    def forward(self, x):
        x = self.conv1(x)
        x = self.conv2(x)
        x = self.conv3(x)
        return x

# Chú ý Attention Refinement Module (ARM)
class AttentionRefinementModule(nn.Module):
    def __init__(self, in_channels, out_channels):
        super(AttentionRefinementModule, self).__init__()
        self.conv = ConvBlock(in_channels, out_channels, kernel_size=3, stride=1, padding=1)
        self.attention = nn.Sequential(
            nn.AdaptiveAvgPool2d(1),
            nn.Conv2d(out_channels, out_channels, kernel_size=1, bias=False),
            nn.BatchNorm2d(out_channels),
            nn.Sigmoid()
        )

    def forward(self, x):
        x = self.conv(x)
        attn = self.attention(x)
        return x * attn

# Context Path (Đường dẫn Ngữ cảnh) - Tương tự ResNet18 đơn giản hóa
class ContextPath(nn.Module):
    def __init__(self):
        super(ContextPath, self).__init__()
        # Để đơn giản, ta dùng các ConvBlock thay vì ResNet nguyên bản để có thể load weights tùy chỉnh
        # Lưu ý: Cấu trúc này cần tương thích với file weights 79999_iter.pth
        # Đây là cấu trúc mô phỏng ResNet18 backbone thường dùng cho BiSeNet
        self.conv1 = ConvBlock(3, 64, kernel_size=7, stride=2, padding=3)
        self.maxpool = nn.MaxPool2d(kernel_size=3, stride=2, padding=1)
        
        self.layer1 = self._make_layer(64, 64, 2)
        self.layer2 = self._make_layer(64, 128, 2, stride=2)
        self.layer3 = self._make_layer(128, 256, 2, stride=2)
        self.layer4 = self._make_layer(256, 512, 2, stride=2)

        self.arm16 = AttentionRefinementModule(256, 128)
        self.arm32 = AttentionRefinementModule(512, 128)
        
        self.global_context = nn.Sequential(
            nn.AdaptiveAvgPool2d(1),
            ConvBlock(512, 128, kernel_size=1, stride=1, padding=0)
        )

    def _make_layer(self, in_channels, out_channels, blocks, stride=1):
        layers = []
        layers.append(ConvBlock(in_channels, out_channels, stride=stride))
        for _ in range(1, blocks):
            layers.append(ConvBlock(out_channels, out_channels, stride=1))
        return nn.Sequential(*layers)

    def forward(self, x):
        x = self.conv1(x)
        x = self.maxpool(x)
        
        feat4 = self.layer1(x)
        feat8 = self.layer2(feat4)
        feat16 = self.layer3(feat8)
        feat32 = self.layer4(feat16)

        arm16 = self.arm16(feat16)
        arm32 = self.arm32(feat32)
        
        global_ctx = self.global_context(feat32)
        global_ctx = F.interpolate(global_ctx, size=arm32.size()[2:], mode='bilinear', align_corners=True)
        
        arm32 = arm32 + global_ctx
        arm32 = F.interpolate(arm32, size=arm16.size()[2:], mode='bilinear', align_corners=True)
        
        context_out = arm16 + arm32
        return context_out, feat32

# Feature Fusion Module (Mô-đun Kết hợp Đặc trưng)
class FeatureFusionModule(nn.Module):
    def __init__(self, in_channels, out_channels):
        super(FeatureFusionModule, self).__init__()
        self.convblock = ConvBlock(in_channels, out_channels, kernel_size=1, stride=1, padding=0)
        self.attention = nn.Sequential(
            nn.AdaptiveAvgPool2d(1),
            nn.Conv2d(out_channels, out_channels // 4, kernel_size=1, bias=False),
            nn.ReLU(inplace=True),
            nn.Conv2d(out_channels // 4, out_channels, kernel_size=1, bias=False),
            nn.Sigmoid()
        )

    def forward(self, f_sp, f_cp):
        # f_sp: spatial path feature, f_cp: context path feature
        # Upsample f_cp cho khớp kích thước với f_sp
        if f_cp.size()[2:] != f_sp.size()[2:]:
            f_cp = F.interpolate(f_cp, size=f_sp.size()[2:], mode='bilinear', align_corners=True)
        feat = torch.cat([f_sp, f_cp], dim=1)
        feat = self.convblock(feat)
        attn = self.attention(feat)
        return feat + feat * attn

# Mô hình BiSeNet hoàn chỉnh
class BiSeNet(nn.Module):
    def __init__(self, num_classes=19):
        super(BiSeNet, self).__init__()
        self.spatial_path = SpatialPath()
        self.context_path = ContextPath()
        
        # In channels của Fusion Module sẽ phụ thuộc vào output của Spatial (64) + Context (128)
        self.ffm = FeatureFusionModule(64 + 128, 256)
        
        # Lớp phân loại cuối cùng
        self.conv_out = nn.Conv2d(256, num_classes, kernel_size=1)

    def forward(self, x):
        h, w = x.size()[2:]
        
        # Rút trích đặc trưng
        f_sp = self.spatial_path(x)
        f_cp, _ = self.context_path(x)
        
        # Kết hợp
        feat_fuse = self.ffm(f_sp, f_cp)
        
        # Dự đoán phân vùng
        out = self.conv_out(feat_fuse)
        
        # Resize lại kích thước ảnh đầu vào
        out = F.interpolate(out, size=(h, w), mode='bilinear', align_corners=True)
        return out
