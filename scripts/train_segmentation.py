import argparse
import yaml
import torch

def train():
    # Parse arguments
    parser = argparse.ArgumentParser(description="Train U-Net/Mask R-CNN")
    parser.add_argument('--config', type=str, default='config/segmentation_cfg.yaml', help='Path to config file')
    args = parser.parse_args()

    # Load config
    with open(args.config, 'r') as f:
        cfg = yaml.safe_load(f)

    print(f"Training Face Segmentation Model: {cfg['model']['name']}")
    print(f"Using Device: {cfg['train']['device']}")
    
    # TODO: Initialize dataset, dataloader, model, optimizer, loss
    
    # TODO: Training loop
    
if __name__ == '__main__':
    train()
