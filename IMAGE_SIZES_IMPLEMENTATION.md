# Image Sizes Implementation Guide

## Overview
The frontend expects images to be served in multiple sizes for optimal performance. The backend should generate and serve these sizes through a CDN (CloudFront/Cloudflare).

## Required Image Sizes

When an image is uploaded, Laravel should generate the following sizes:

1. **thumb** - 300px width (for thumbnails in grids)
2. **medium** - 800px width (for medium-sized displays)
3. **large** - 1800px width (for large displays and downloads)
4. **original** - Full resolution (for full-size downloads)

## API Response Format

The `/api/shoots/{id}/files` endpoint should return files with the following structure:

```json
{
  "data": [
    {
      "id": 123,
      "filename": "IMG_001.jpg",
      "workflow_stage": "completed",
      "thumb_url": "https://cdn.example.com/shoots/123/thumb/IMG_001.jpg",
      "medium_url": "https://cdn.example.com/shoots/123/medium/IMG_001.jpg",
      "large_url": "https://cdn.example.com/shoots/123/large/IMG_001.jpg",
      "original_url": "https://cdn.example.com/shoots/123/original/IMG_001.jpg",
      "width": 6000,
      "height": 4000,
      "file_size": 5242880
    }
  ]
}
```

## Download Endpoint

The `/api/shoots/{id}/files/download` endpoint should:

1. Accept POST request with:
   ```json
   {
     "file_ids": [1, 2, 3],
     "size": "small" // or "original"
   }
   ```

2. For "small" size: Generate 1800x1200px optimized images
3. For "original" size: Return full-resolution images
4. Zip all selected files and return as blob

## Implementation Steps

1. **Image Processing on Upload**
   - Use Intervention Image or similar library
   - Generate all sizes on upload
   - Store paths in database
   - Upload to CDN (S3 → CloudFront or similar)

2. **Optimization**
   - Compress images (JPEG quality 85-90)
   - Use WebP format when possible
   - Strip EXIF data for web sizes

3. **CDN Configuration**
   - Set up CloudFront/Cloudflare distribution
   - Configure caching headers
   - Enable image optimization at CDN level

4. **Database Schema**
   Add columns to `shoot_files` table:
   - `thumb_path`
   - `medium_path`
   - `large_path`
   - `original_path`
   - `width`
   - `height`

## Example Laravel Code

```php
use Intervention\Image\ImageManagerStatic as Image;

// On file upload
$image = Image::make($uploadedFile);

// Generate sizes
$thumb = $image->resize(300, null, function ($constraint) {
    $constraint->aspectRatio();
})->encode('jpg', 85);

$medium = $image->resize(800, null, function ($constraint) {
    $constraint->aspectRatio();
})->encode('jpg', 85);

$large = $image->resize(1800, null, function ($constraint) {
    $constraint->aspectRatio();
})->encode('jpg', 90);

// Upload to S3/CDN
Storage::disk('s3')->put("shoots/{$shootId}/thumb/{$filename}", $thumb);
Storage::disk('s3')->put("shoots/{$shootId}/medium/{$filename}", $medium);
Storage::disk('s3')->put("shoots/{$shootId}/large/{$filename}", $large);
Storage::disk('s3')->put("shoots/{$shootId}/original/{$filename}", $original);
```



