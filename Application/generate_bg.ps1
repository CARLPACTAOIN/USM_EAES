Add-Type -AssemblyName System.Drawing
$width = 1080
$height = 1920

$outDir = "c:\Users\cjcar\Documents\Capstone_3rd_Year\System\Application\assets\icon"
if (!(Test-Path $outDir)) {
    New-Item -ItemType Directory -Force -Path $outDir | Out-Null
}

$bmp = New-Object System.Drawing.Bitmap $width, $height
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias

# Try Radial Gradient
$path = New-Object System.Drawing.Drawing2D.GraphicsPath
# Make ellipse large enough to cover the corners
$radius = [math]::Sqrt($width*$width + $height*$height)
$path.AddEllipse([float](($width-$radius)/2), [float](($height-$radius)/2), [float]$radius, [float]$radius)

$brush = New-Object System.Drawing.Drawing2D.PathGradientBrush $path
$brush.CenterPoint = New-Object System.Drawing.PointF ($width/2), ($height/2)
$brush.CenterColor = [System.Drawing.Color]::FromArgb(255, 27, 77, 62) # 1B4D3E
$surroundColor = [System.Drawing.Color]::FromArgb(255, 15, 46, 37) # 0F2E25
$brush.SurroundColors = @($surroundColor)

$g.FillRectangle($brush, 0, 0, $width, $height)

$bmp.Save("$outDir\splash_bg.png", [System.Drawing.Imaging.ImageFormat]::Png)

$g.Dispose()
$bmp.Dispose()
$brush.Dispose()
$path.Dispose()

Write-Output "Background generated successfully."
