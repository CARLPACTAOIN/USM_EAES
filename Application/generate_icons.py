import os
from PIL import Image, ImageDraw

def draw_qr_glyph(draw, cx, cy, size, color):
    # Draw a stylized QR code (just some squares and dots)
    box_size = size * 0.15
    padding = size * 0.05
    # Top-Left finder
    draw.rectangle([cx - size/2, cy - size/2, cx - size/2 + box_size, cy - size/2 + box_size], outline=color, width=8)
    draw.rectangle([cx - size/2 + padding, cy - size/2 + padding, cx - size/2 + box_size - padding, cy - size/2 + box_size - padding], fill=color)
    
    # Top-Right finder
    draw.rectangle([cx + size/2 - box_size, cy - size/2, cx + size/2, cy - size/2 + box_size], outline=color, width=8)
    draw.rectangle([cx + size/2 - box_size + padding, cy - size/2 + padding, cx + size/2 - padding, cy - size/2 + box_size - padding], fill=color)

    # Bottom-Left finder
    draw.rectangle([cx - size/2, cy + size/2 - box_size, cx - size/2 + box_size, cy + size/2], outline=color, width=8)
    draw.rectangle([cx - size/2 + padding, cy + size/2 - box_size + padding, cx - size/2 + box_size - padding, cy + size/2 - padding], fill=color)

    # Some internal dots/lines
    draw.rectangle([cx - size*0.1, cy - size*0.1, cx + size*0.1, cy + size*0.1], fill=color)
    draw.rectangle([cx + size*0.2, cy - size*0.2, cx + size*0.4, cy - size*0.1], fill=color)
    draw.rectangle([cx - size*0.4, cy + size*0.2, cx - size*0.2, cy + size*0.4], fill=color)
    draw.rectangle([cx + size*0.1, cy + size*0.2, cx + size*0.4, cy + size*0.5], fill=color)

def draw_brackets(draw, width, height, size, bracket_len, color, line_width):
    # Top-Left
    draw.line([(width-size)/2, (height-size)/2, (width-size)/2 + bracket_len, (height-size)/2], fill=color, width=line_width)
    draw.line([(width-size)/2, (height-size)/2, (width-size)/2, (height-size)/2 + bracket_len], fill=color, width=line_width)
    # Top-Right
    draw.line([(width+size)/2, (height-size)/2, (width+size)/2 - bracket_len, (height-size)/2], fill=color, width=line_width)
    draw.line([(width+size)/2, (height-size)/2, (width+size)/2, (height-size)/2 + bracket_len], fill=color, width=line_width)
    # Bottom-Left
    draw.line([(width-size)/2, (height+size)/2, (width-size)/2 + bracket_len, (height+size)/2], fill=color, width=line_width)
    draw.line([(width-size)/2, (height+size)/2, (width-size)/2, (height+size)/2 - bracket_len], fill=color, width=line_width)
    # Bottom-Right
    draw.line([(width+size)/2, (height+size)/2, (width+size)/2 - bracket_len, (height+size)/2], fill=color, width=line_width)
    draw.line([(width+size)/2, (height+size)/2, (width+size)/2, (height+size)/2 - bracket_len], fill=color, width=line_width)

def generate_icons():
    width, height = 1024, 1024
    gold = (212, 168, 67, 255) # #D4A843
    
    # Foreground image (transparent)
    fg_img = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    fg_draw = ImageDraw.Draw(fg_img)
    
    # Safe margins: keep within central 600x600 to be extremely safe for masking
    bracket_size = 600
    qr_size = 350
    bracket_len = 100
    line_width = 32

    draw_brackets(fg_draw, width, height, bracket_size, bracket_len, gold, line_width)
    draw_qr_glyph(fg_draw, width/2, height/2, qr_size, gold)

    # Master image (radial background + foreground)
    master_img = Image.new("RGBA", (width, height))
    master_draw = ImageDraw.Draw(master_img)
    
    # Draw radial gradient background
    cx, cy = width/2, height/2
    max_radius = (cx**2 + cy**2)**0.5
    for r in range(int(max_radius), 0, -2):
        # interpolate between #1B4D3E (27, 77, 62) at center and #0F2E25 (15, 46, 37) at edges
        ratio = r / max_radius
        r_c = int(27 * (1 - ratio) + 15 * ratio)
        g_c = int(77 * (1 - ratio) + 46 * ratio)
        b_c = int(62 * (1 - ratio) + 37 * ratio)
        master_draw.ellipse([cx - r, cy - r, cx + r, cy + r], fill=(r_c, g_c, b_c, 255))
        
    master_img.paste(fg_img, (0, 0), fg_img)

    # Save
    out_dir = r"c:\Users\cjcar\Documents\Capstone_3rd_Year\System\Application\assets\icon"
    os.makedirs(out_dir, exist_ok=True)
    master_img.save(os.path.join(out_dir, "app_icon.png"))
    fg_img.save(os.path.join(out_dir, "app_icon_foreground.png"))
    print("Icons generated successfully!")

if __name__ == "__main__":
    generate_icons()
