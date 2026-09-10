#!/usr/bin/env python3
"""
LaMa Inpainting Script for KomikoID-MTL
Automatically removes text from comic images using AI-powered inpainting.

Usage:
    python lama_inpaint.py <image_path> <text_blocks_json> [device] [dilation]
    
Example:
    python lama_inpaint.py "comics/page1.jpg" '[{"x":100,"y":50,"width":200,"height":30}]' cpu 5
    
Output:
    JSON with output path: {"success": true, "output_path": "comics/page1_inpainted.jpg"}
"""

import sys
import os
import json
import numpy as np
from pathlib import Path

# Check if PIL is available
try:
    from PIL import Image
except ImportError:
    print(json.dumps({"success": False, "error": "PIL not installed. Run: pip install Pillow"}))
    sys.exit(1)

# Check if cv2 is available for mask dilation
try:
    import cv2
    HAS_CV2 = True
except ImportError:
    HAS_CV2 = False


class LamaInpainter:
    """Handles AI inpainting using LaMa model via simple-lama-inpainting."""
    
    def __init__(self, device: str = 'cpu'):
        self.device = device
        self.model = None
        self._load_model()
    
    def _load_model(self):
        """Load the LaMa inpainting model."""
        # Try simple-lama-inpainting first (more compatible with PyTorch 2.9+)
        try:
            from simple_lama_inpainting import SimpleLama
            self.model = SimpleLama(device=self.device)
            self.model_type = 'simple_lama'
            return
        except ImportError:
            pass
        except Exception as e:
            # Try without device argument
            try:
                from simple_lama_inpainting import SimpleLama
                self.model = SimpleLama()
                self.model_type = 'simple_lama'
                return
            except:
                pass
        
        # Fallback to iopaint (may not work with PyTorch 2.9+)
        try:
            from iopaint.model_manager import ModelManager
            self.model_manager = ModelManager(device=self.device)
            self.model = self.model_manager.load_model("lama")
            self.model_type = 'iopaint'
        except ImportError:
            try:
                from iopaint import IOPaint
                self.model = IOPaint(model_name="lama", device=self.device)
                self.model_type = 'iopaint_legacy'
            except ImportError:
                raise ImportError(
                    "Neither simple-lama-inpainting nor iopaint installed. "
                    "Run: pip install simple-lama-inpainting"
                )
    
    def create_mask(self, image_shape: tuple, text_blocks: list, dilation: int = 5) -> np.ndarray:
        """
        Create binary mask from text block coordinates.
        
        Args:
            image_shape: (height, width) of the image
            text_blocks: List of dicts with x, y, width, height
            dilation: Pixels to expand the mask (ensures full text coverage)
        
        Returns:
            Binary mask array (255 = inpaint, 0 = keep)
        """
        height, width = image_shape[:2]
        mask = np.zeros((height, width), dtype=np.uint8)
        
        for block in text_blocks:
            x = int(block.get('x', 0))
            y = int(block.get('y', 0))
            w = int(block.get('width', 0))
            h = int(block.get('height', 0))
            
            if w <= 0 or h <= 0:
                continue
            
            # Add dilation to ensure full text coverage
            x1 = max(0, x - dilation)
            y1 = max(0, y - dilation)
            x2 = min(width, x + w + dilation)
            y2 = min(height, y + h + dilation)
            
            # Fill the mask region
            mask[y1:y2, x1:x2] = 255
        
        # Apply morphological dilation if cv2 is available
        if HAS_CV2 and dilation > 0:
            kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (dilation*2+1, dilation*2+1))
            mask = cv2.dilate(mask, kernel, iterations=1)
        
        return mask
    
    def inpaint(self, image_path: str, text_blocks: list, output_path: str = None, 
                dilation: int = 5) -> str:
        """
        Inpaint text regions in an image.
        
        Args:
            image_path: Path to the input image
            text_blocks: List of text block coordinates
            output_path: Optional output path (defaults to <name>_inpainted.ext)
            dilation: Mask dilation in pixels
        
        Returns:
            Path to the output image
        """
        # Load image
        image = Image.open(image_path).convert('RGB')
        image_np = np.array(image)
        
        # Create mask
        mask = self.create_mask(image_np.shape, text_blocks, dilation)
        
        # If no text blocks or empty mask, return original
        if np.max(mask) == 0:
            return image_path
        
        # Convert mask to PIL
        mask_pil = Image.fromarray(mask).convert('L')
        
        # Run inpainting based on model type
        try:
            if self.model_type == 'simple_lama':
                # simple-lama-inpainting API
                result = self.model(image, mask_pil)
            elif self.model_type == 'iopaint':
                # New iopaint API
                result = self.model(image_np, mask)
                if isinstance(result, np.ndarray):
                    result = Image.fromarray(result)
            else:
                # Legacy iopaint API
                result = self.model(image, mask_pil)
                if isinstance(result, np.ndarray):
                    result = Image.fromarray(result)
        except Exception as e:
            raise RuntimeError(f"Inpainting failed: {str(e)}")
        
        # Generate output path
        if output_path is None:
            path = Path(image_path)
            output_path = str(path.parent / f"{path.stem}_inpainted{path.suffix}")
        
        # Ensure output directory exists
        os.makedirs(os.path.dirname(output_path) or '.', exist_ok=True)
        
        # Save result
        result.save(output_path, quality=95)
        
        return output_path


def main():
    """Main entry point."""
    if len(sys.argv) < 3:
        print(json.dumps({
            "success": False,
            "error": "Usage: python lama_inpaint.py <image_path> <text_blocks_json> [device] [dilation]"
        }))
        sys.exit(1)
    
    image_path = sys.argv[1]
    text_blocks_json = sys.argv[2]
    device = sys.argv[3] if len(sys.argv) > 3 else 'cpu'
    dilation = int(sys.argv[4]) if len(sys.argv) > 4 else 5
    
    # Validate image path
    if not os.path.exists(image_path):
        print(json.dumps({
            "success": False,
            "error": f"Image not found: {image_path}"
        }))
        sys.exit(1)
    
    # Parse text blocks - support reading from file with @ prefix
    try:
        if text_blocks_json.startswith('@'):
            # Read JSON from file
            json_file_path = text_blocks_json[1:]  # Remove @ prefix
            with open(json_file_path, 'r', encoding='utf-8') as f:
                text_blocks = json.load(f)
        else:
            text_blocks = json.loads(text_blocks_json)
            
        if not isinstance(text_blocks, list):
            raise ValueError("text_blocks must be a list")
    except json.JSONDecodeError as e:
        print(json.dumps({
            "success": False,
            "error": f"Invalid JSON: {str(e)}"
        }))
        sys.exit(1)
    
    try:
        # Initialize inpainter
        inpainter = LamaInpainter(device=device)
        
        # Run inpainting
        output_path = inpainter.inpaint(image_path, text_blocks, dilation=dilation)
        
        print(json.dumps({
            "success": True,
            "output_path": output_path
        }))
        
    except ImportError as e:
        print(json.dumps({
            "success": False,
            "error": str(e),
            "install_hint": "pip install simple-lama-inpainting"
        }))
        sys.exit(1)
    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": str(e)
        }))
        sys.exit(1)


if __name__ == "__main__":
    main()
