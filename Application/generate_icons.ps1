Add-Type -AssemblyName System.Drawing
$width = 1024
$height = 1024

$outDir = "c:\Users\cjcar\Documents\Capstone_3rd_Year\System\Application\assets\icon"
if (!(Test-Path $outDir)) {
    New-Item -ItemType Directory -Force -Path $outDir | Out-Null
}

# Foreground
$bmpFg = New-Object System.Drawing.Bitmap $width, $height
$gFg = [System.Drawing.Graphics]::FromImage($bmpFg)
$gFg.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$gFg.Clear([System.Drawing.Color]::Transparent)

$gold = [System.Drawing.Color]::FromArgb(255, 212, 168, 67) # #D4A843
$penGold = New-Object System.Drawing.Pen $gold, 32

$size = 600
$len = 100
$cx = 512
$cy = 512

# Top-Left Bracket
$gFg.DrawLine($penGold, ($cx - $size/2), ($cy - $size/2), ($cx - $size/2 + $len), ($cy - $size/2))
$gFg.DrawLine($penGold, ($cx - $size/2), ($cy - $size/2), ($cx - $size/2), ($cy - $size/2 + $len))

# Top-Right Bracket
$gFg.DrawLine($penGold, ($cx + $size/2), ($cy - $size/2), ($cx + $size/2 - $len), ($cy - $size/2))
$gFg.DrawLine($penGold, ($cx + $size/2), ($cy - $size/2), ($cx + $size/2), ($cy - $size/2 + $len))

# Bottom-Left Bracket
$gFg.DrawLine($penGold, ($cx - $size/2), ($cy + $size/2), ($cx - $size/2 + $len), ($cy + $size/2))
$gFg.DrawLine($penGold, ($cx - $size/2), ($cy + $size/2), ($cx - $size/2), ($cy + $size/2 - $len))

# Bottom-Right Bracket
$gFg.DrawLine($penGold, ($cx + $size/2), ($cy + $size/2), ($cx + $size/2 - $len), ($cy + $size/2))
$gFg.DrawLine($penGold, ($cx + $size/2), ($cy + $size/2), ($cx + $size/2), ($cy + $size/2 - $len))

# QR outline boxes
$qrSize = 350
$boxSize = $qrSize * 0.15
$pad = $qrSize * 0.05
$penBox = New-Object System.Drawing.Pen $gold, 8
$brushGold = New-Object System.Drawing.SolidBrush $gold

# Top-Left finder
$gFg.DrawRectangle($penBox, [int]($cx - $qrSize/2), [int]($cy - $qrSize/2), [int]$boxSize, [int]$boxSize)
$gFg.FillRectangle($brushGold, [int]($cx - $qrSize/2 + $pad), [int]($cy - $qrSize/2 + $pad), [int]($boxSize - 2*$pad), [int]($boxSize - 2*$pad))

# Top-Right finder
$gFg.DrawRectangle($penBox, [int]($cx + $qrSize/2 - $boxSize), [int]($cy - $qrSize/2), [int]$boxSize, [int]$boxSize)
$gFg.FillRectangle($brushGold, [int]($cx + $qrSize/2 - $boxSize + $pad), [int]($cy - $qrSize/2 + $pad), [int]($boxSize - 2*$pad), [int]($boxSize - 2*$pad))

# Bottom-Left finder
$gFg.DrawRectangle($penBox, [int]($cx - $qrSize/2), [int]($cy + $qrSize/2 - $boxSize), [int]$boxSize, [int]$boxSize)
$gFg.FillRectangle($brushGold, [int]($cx - $qrSize/2 + $pad), [int]($cy + $qrSize/2 - $boxSize + $pad), [int]($boxSize - 2*$pad), [int]($boxSize - 2*$pad))

# Inner dots
$gFg.FillRectangle($brushGold, [int]($cx - $qrSize*0.1), [int]($cy - $qrSize*0.1), [int]($qrSize*0.2), [int]($qrSize*0.2))
$gFg.FillRectangle($brushGold, [int]($cx + $qrSize*0.2), [int]($cy - $qrSize*0.2), [int]($qrSize*0.2), [int]($qrSize*0.1))
$gFg.FillRectangle($brushGold, [int]($cx - $qrSize*0.4), [int]($cy + $qrSize*0.2), [int]($qrSize*0.2), [int]($qrSize*0.2))
$gFg.FillRectangle($brushGold, [int]($cx + $qrSize*0.1), [int]($cy + $qrSize*0.2), [int]($qrSize*0.3), [int]($qrSize*0.3))

$bmpFg.Save("$outDir\app_icon_foreground.png", [System.Drawing.Imaging.ImageFormat]::Png)

# Master Icon
$bmp = New-Object System.Drawing.Bitmap $width, $height
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias

$path = New-Object System.Drawing.Drawing2D.GraphicsPath
$path.AddEllipse(0, 0, $width, $height)
$brush = New-Object System.Drawing.Drawing2D.PathGradientBrush $path
$brush.CenterPoint = New-Object System.Drawing.PointF ($width/2), ($height/2)
$brush.CenterColor = [System.Drawing.Color]::FromArgb(255, 27, 77, 62) # 1B4D3E
$surroundColor = [System.Drawing.Color]::FromArgb(255, 15, 46, 37) # 0F2E25
$brush.SurroundColors = @($surroundColor)

$g.FillRectangle($brush, 0, 0, $width, $height)

# Draw foreground onto master
$g.DrawImage($bmpFg, 0, 0)

$bmp.Save("$outDir\app_icon.png", [System.Drawing.Imaging.ImageFormat]::Png)

$g.Dispose()
$gFg.Dispose()
$bmp.Dispose()
$bmpFg.Dispose()

Write-Output "Icons generated successfully."
