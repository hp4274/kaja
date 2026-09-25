<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
$db = getDbConnection();

function resizeAndCrop($sourcePath, $destPath, $targetWidth, $targetHeight, $mime) {
    switch ($mime) {
        case 'image/jpeg':
            $sourceImage = @imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $sourceImage = @imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $sourceImage = @imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $sourceImage = @imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }
    
    if (!$sourceImage) {
        return false;
    }
    
    list($origWidth, $origHeight) = getimagesize($sourcePath);
    
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    
    // Handle transparency for PNG and WebP
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
    }
    
    $origAspect = $origWidth / $origHeight;
    $targetAspect = $targetWidth / $targetHeight;
    
    if ($origAspect >= $targetAspect) {
        // Wider than aspect ratio: fit height, crop width
        $srcH = $origHeight;
        $srcW = intval($targetWidth * ($origHeight / $targetHeight));
        $srcX = intval(($origWidth - $srcW) / 2);
        $srcY = 0;
    } else {
        // Taller than aspect ratio: fit width, crop height
        $srcW = $origWidth;
        $srcH = intval($targetHeight * ($origWidth / $targetWidth));
        $srcX = 0;
        $srcY = intval(($origHeight - $srcH) / 2);
    }
    
    $ok = imagecopyresampled(
        $targetImage, 
        $sourceImage, 
        0, 0, 
        $srcX, $srcY, 
        $targetWidth, $targetHeight, 
        $srcW, $srcH
    );
    
    if ($ok) {
        switch ($mime) {
            case 'image/jpeg':
                $ok = imagejpeg($targetImage, $destPath, 85);
                break;
            case 'image/png':
                $ok = imagepng($targetImage, $destPath, 6);
                break;
            case 'image/gif':
                $ok = imagegif($targetImage, $destPath);
                break;
            case 'image/webp':
                $ok = imagewebp($targetImage, $destPath, 80);
                break;
        }
    }
    
    imagedestroy($sourceImage);
    imagedestroy($targetImage);
    
    return $ok;
}

function processImageUpload($fileKey, $slug) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmpPath = $_FILES[$fileKey]['tmp_name'];
    
    // Validate it's an image
    $info = @getimagesize($tmpPath);
    if ($info === false) {
        throw new Exception("Uploaded file is not a valid image.");
    }
    
    $mime = $info['mime'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowedMimes)) {
        throw new Exception("Only JPG, PNG, GIF, and WEBP images are allowed.");
    }
    
    // Determine extension
    $ext = 'jpg';
    if ($mime === 'image/png') $ext = 'png';
    elseif ($mime === 'image/gif') $ext = 'gif';
    elseif ($mime === 'image/webp') $ext = 'webp';
    
    $filename = $slug . '_' . uniqid() . '.' . $ext;
    $targetDir = __DIR__ . '/../../uploads/blogs/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    $destPath = $targetDir . $filename;
    
    // Resize and crop to 1200x800
    $success = resizeAndCrop($tmpPath, $destPath, 1200, 800, $mime);
    if (!$success) {
        throw new Exception("Failed to process and resize image.");
    }
    
    return 'uploads/blogs/' . $filename;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

try {
    switch ($action) {

        /* ── List all blogs ── */
        case 'list':
            $stmt = $db->query("SELECT `id`,`title`,`slug`,`excerpt`,`category`,`read_time`,`cover_image`,`status`,`created_at` FROM `blogs` ORDER BY `created_at` DESC");
            $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'blogs'=>$blogs]);
            break;

        /* ── Get single blog ── */
        case 'get':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success'=>false,'error'=>'Invalid blog ID']);
                exit;
            }
            $stmt = $db->prepare("SELECT * FROM `blogs` WHERE `id`=:id");
            $stmt->execute([':id'=>$id]);
            $blog = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$blog) {
                echo json_encode(['success'=>false,'error'=>'Blog not found']);
                exit;
            }
            echo json_encode(['success'=>true,'blog'=>$blog]);
            break;

        /* ── Create new blog ── */
        case 'create':
            $title   = trim($_POST['title'] ?? '');
            $slug    = trim($_POST['slug'] ?? '');
            $excerpt = trim($_POST['excerpt'] ?? '');
            $content = $_POST['content'] ?? '';
            $category = trim($_POST['category'] ?? '');
            $readTime = intval($_POST['read_time'] ?? 5);
            $coverImage = trim($_POST['cover_image'] ?? '') ?: null;
            $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

            if (!$title || !$slug || !$excerpt || !$content || !$category) {
                echo json_encode(['success'=>false,'error'=>'Title, slug, excerpt, content, and category are required']);
                exit;
            }

            // Generate slug from title if empty
            if (!$slug) {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
            }

            // Ensure unique slug
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM `blogs` WHERE `slug`=:slug");
            $checkStmt->execute([':slug'=>$slug]);
            if ($checkStmt->fetchColumn() > 0) {
                $slug .= '-' . time();
            }

            // Handle image upload if provided
            if (isset($_FILES['cover_image_file']) && $_FILES['cover_image_file']['error'] === UPLOAD_ERR_OK) {
                try {
                    $uploadedPath = processImageUpload('cover_image_file', $slug);
                    if ($uploadedPath) {
                        $coverImage = $uploadedPath;
                    }
                } catch (Exception $e) {
                    echo json_encode(['success'=>false,'error'=>publicError($e)]);
                    exit;
                }
            }

            $stmt = $db->prepare("INSERT INTO `blogs` (`title`,`slug`,`excerpt`,`content`,`category`,`read_time`,`cover_image`,`status`) VALUES (:title,:slug,:excerpt,:content,:category,:read_time,:cover_image,:status)");
            $stmt->execute([
                ':title'       => $title,
                ':slug'        => $slug,
                ':excerpt'     => $excerpt,
                ':content'     => $content,
                ':category'    => $category,
                ':read_time'   => $readTime,
                ':cover_image' => $coverImage,
                ':status'      => $status,
            ]);
            $newId = $db->lastInsertId();

            // Log activity
            $desc = "Blog post \"{$title}\" created as {$status}";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('blog_created',:d,'blog',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$newId]);

            echo json_encode(['success'=>true,'id'=>$newId,'slug'=>$slug]);
            break;

        /* ── Update existing blog ── */
        case 'update':
            $id      = intval($_POST['id'] ?? 0);
            $title   = trim($_POST['title'] ?? '');
            $slug    = trim($_POST['slug'] ?? '');
            $excerpt = trim($_POST['excerpt'] ?? '');
            $content = $_POST['content'] ?? '';
            $category = trim($_POST['category'] ?? '');
            $readTime = intval($_POST['read_time'] ?? 5);
            $coverImage = trim($_POST['cover_image'] ?? '') ?: null;
            $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

            if (!$id || !$title || !$slug || !$excerpt || !$content || !$category) {
                echo json_encode(['success'=>false,'error'=>'All required fields must be filled']);
                exit;
            }

            // Ensure unique slug (excluding current post)
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM `blogs` WHERE `slug`=:slug AND `id`!=:id");
            $checkStmt->execute([':slug'=>$slug, ':id'=>$id]);
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['success'=>false,'error'=>'Slug already exists for another post']);
                exit;
            }

            // Handle image upload if provided
            if (isset($_FILES['cover_image_file']) && $_FILES['cover_image_file']['error'] === UPLOAD_ERR_OK) {
                try {
                    $uploadedPath = processImageUpload('cover_image_file', $slug);
                    if ($uploadedPath) {
                        $coverImage = $uploadedPath;
                    }
                } catch (Exception $e) {
                    echo json_encode(['success'=>false,'error'=>publicError($e)]);
                    exit;
                }
            }

            $stmt = $db->prepare("UPDATE `blogs` SET `title`=:title, `slug`=:slug, `excerpt`=:excerpt, `content`=:content, `category`=:category, `read_time`=:read_time, `cover_image`=:cover_image, `status`=:status WHERE `id`=:id");
            $stmt->execute([
                ':title'       => $title,
                ':slug'        => $slug,
                ':excerpt'     => $excerpt,
                ':content'     => $content,
                ':category'    => $category,
                ':read_time'   => $readTime,
                ':cover_image' => $coverImage,
                ':status'      => $status,
                ':id'          => $id,
            ]);

            // Log activity
            $desc = "Blog post \"{$title}\" updated";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('blog_updated',:d,'blog',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$id]);

            echo json_encode(['success'=>true]);
            break;

        /* ── Delete blog ── */
        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success'=>false,'error'=>'Invalid blog ID']);
                exit;
            }

            // Fetch title for log
            $row = $db->prepare("SELECT `title` FROM `blogs` WHERE `id`=:id");
            $row->execute([':id'=>$id]);
            $blogTitle = $row->fetchColumn() ?: "ID #{$id}";

            $stmt = $db->prepare("DELETE FROM `blogs` WHERE `id`=:id");
            $stmt->execute([':id'=>$id]);

            // Log activity
            $desc = "Blog post \"{$blogTitle}\" deleted";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('blog_deleted',:d,'blog',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$id]);

            echo json_encode(['success'=>true]);
            break;

        /* ── Toggle status ── */
        case 'toggle_status':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success'=>false,'error'=>'Invalid blog ID']);
                exit;
            }

            $row = $db->prepare("SELECT `status`,`title` FROM `blogs` WHERE `id`=:id");
            $row->execute([':id'=>$id]);
            $blog = $row->fetch(PDO::FETCH_ASSOC);

            if (!$blog) {
                echo json_encode(['success'=>false,'error'=>'Blog not found']);
                exit;
            }

            $newStatus = $blog['status'] === 'published' ? 'draft' : 'published';
            $db->prepare("UPDATE `blogs` SET `status`=:s WHERE `id`=:id")->execute([':s'=>$newStatus, ':id'=>$id]);

            // Log activity
            $desc = "Blog \"{$blog['title']}\" status changed to {$newStatus}";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('blog_status_changed',:d,'blog',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$id]);

            echo json_encode(['success'=>true,'new_status'=>$newStatus]);
            break;

        default:
            echo json_encode(['success'=>false,'error'=>'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>publicError($e)]);
}
