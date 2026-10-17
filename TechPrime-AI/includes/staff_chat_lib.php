<?php
/**
 * Shared helpers for staff-only messaging (admin / retail / inventory).
 */

if (!function_exists('staff_chat_allowed_roles')) {
    /** @return list<string> */
    function staff_chat_allowed_roles(): array
    {
        return ['admin', 'retail_officer', 'inventory_custodian'];
    }
}

if (!function_exists('staff_chat_role_ok')) {
    function staff_chat_role_ok(?string $role): bool
    {
        return $role !== null && in_array($role, staff_chat_allowed_roles(), true);
    }
}

if (!function_exists('staff_chat_has_is_read')) {
    function staff_chat_has_is_read(PDO $db): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'messages' AND column_name = 'is_read'"
        );
        $stmt->execute();
        $has = (bool)$stmt->fetchColumn();
        return $has;
    }
}

if (!function_exists('staff_chat_endpoint_href')) {
    function staff_chat_endpoint_href(): string
    {
        return '../includes/staff_chat_messages.php';
    }
}

if (!function_exists('staff_messages_page_href')) {
    function staff_messages_page_href(): string
    {
        return match ((string)($_SESSION['role'] ?? '')) {
            'admin' => 'admin_messages.php',
            'retail_officer' => 'retail_messages.php',
            'inventory_custodian' => 'inventory_messages.php',
            default => '#',
        };
    }
}

if (!function_exists('staff_chat_current_url')) {
    /** Path + query for the current page (no host), suitable for in-app share links. */
    function staff_chat_current_url(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $uri = strtok($uri, '#') ?: '';
        return $uri !== '' ? $uri : (string)($_SERVER['PHP_SELF'] ?? '');
    }
}

if (!function_exists('staff_chat_emoji_groups')) {
    /**
     * Emoji picker contents. Also the server-side whitelist for reactions,
     * so the picker and the API can never disagree.
     * @return array<string, list<string>>
     */
    function staff_chat_emoji_groups(): array
    {
        return [
            'Smileys' => ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','😉','😊','😇','🥰','😍','🤩','😘','😋','😜','🤪','🤗','🤭','🤔','🤐','😐','😑','😶','😏','😒','🙄','😬','😌','😔','😪','😴','😷','🤒','🥵','🥶','😵','🤯','🥳','😎','🤓','😕','😟','🙁','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬'],
            'Gestures' => ['👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','👋','🤚','🖐️','✋','👏','🙌','🤝','🙏','💪','✍️','👀'],
            'Hearts' => ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','💕','💞','💓','💗','💖','💘','💯','🔥','✨','⭐','🎉','🎊'],
            'Work' => ['✅','❌','⚠️','❗','❓','📌','📎','📦','🛒','🧾','💰','💵','📈','📉','📊','📝','📅','⏰','⏳','🔔','💻','🖥️','⌨️','🖱️','🖨️','📱','🔋','🔌','🛠️','🔧','🚚','🏷️'],
        ];
    }
}

if (!function_exists('staff_chat_quick_reactions')) {
    /** @return list<string> */
    function staff_chat_quick_reactions(): array
    {
        return ['👍', '❤️', '😂', '😮', '😢', '😡'];
    }
}

if (!function_exists('staff_chat_emoji_allowed')) {
    function staff_chat_emoji_allowed(string $emoji): bool
    {
        if (in_array($emoji, staff_chat_quick_reactions(), true)) {
            return true;
        }
        foreach (staff_chat_emoji_groups() as $list) {
            if (in_array($emoji, $list, true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('staff_chat_avatar_url')) {
    /** Profile picture URL (relative to a role folder page), or '' to fall back to initials. */
    function staff_chat_avatar_url(?string $profileImage): string
    {
        require_once __DIR__ . '/client_helpers.php';
        $url = ep_user_profile_image_url($profileImage);
        if ($url === '') {
            return '';
        }
        // Same file name is reused on re-upload, so version it to bust the browser cache.
        $mtime = @filemtime(dirname(__DIR__) . '/assets/' . trim((string)$profileImage));
        return $url . ($mtime ? '?v=' . $mtime : '');
    }
}

if (!function_exists('staff_chat_upload_dir')) {
    /** Private folder (Apache denies it via .htaccess); files are streamed by the chat endpoint only. */
    function staff_chat_upload_dir(): string
    {
        return dirname(__DIR__) . '/uploads/chat';
    }
}

if (!function_exists('staff_chat_max_upload_bytes')) {
    function staff_chat_max_upload_bytes(): int
    {
        return 10 * 1024 * 1024;
    }
}

if (!function_exists('staff_chat_attachment_types')) {
    /**
     * Allowed attachments: extension => MIME types finfo may report for it.
     * The first MIME listed is the one stored and served.
     * @return array<string, list<string>>
     */
    function staff_chat_attachment_types(): array
    {
        $zip = ['application/zip', 'application/octet-stream'];
        return [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'webp' => ['image/webp'],
            'gif'  => ['image/gif'],
            'pdf'  => ['application/pdf'],
            'docx' => array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $zip),
            'xlsx' => array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $zip),
            'pptx' => array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $zip),
            'csv'  => ['text/csv', 'text/plain', 'application/csv'],
            'txt'  => ['text/plain'],
        ];
    }
}

if (!function_exists('staff_chat_is_image_mime')) {
    function staff_chat_is_image_mime(?string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }
}

if (!function_exists('staff_chat_clean_filename')) {
    /** Display-only name: no path, no control/reserved characters, bounded length. */
    function staff_chat_clean_filename(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
        }
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " .\t");
        if ($name === '') {
            $name = 'file';
        }
        if (mb_strlen($name) > 120) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 110) . ($ext !== '' ? '.' . mb_substr($ext, 0, 8) : '');
        }
        return $name;
    }
}

if (!function_exists('staff_chat_inspect_file')) {
    /**
     * Deep content checks for an uploaded file whose extension and MIME already matched.
     * Returns an error message, or '' when the file is acceptable.
     */
    function staff_chat_inspect_file(string $path, string $ext): string
    {
        $data = (string)file_get_contents($path);
        // Executables renamed to an allowed extension.
        if (str_starts_with($data, 'MZ') || str_starts_with($data, "\x7FELF") || str_starts_with($data, '#!')) {
            return 'This file type is not allowed.';
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $info = @getimagesize($path);
            if ($info === false || empty($info[0]) || empty($info[1])) {
                return 'The image appears to be corrupted.';
            }
            if ($info[0] > 12000 || $info[1] > 12000) {
                return 'The image dimensions are too large.';
            }
            // Image/script polyglots: a real photo or screenshot never needs these byte sequences.
            if (preg_match('/<\?php|<\?=|<script[\s>]|<iframe|<svg[\s>]|javascript:/i', $data)) {
                return 'The image contains embedded code and was rejected.';
            }
            return '';
        }

        if ($ext === 'pdf') {
            if (!str_starts_with($data, '%PDF-')) {
                return 'The PDF appears to be corrupted.';
            }
            // Active content: scripts, launch/auto actions, embedded files, rich media.
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|AA)\b#', $data)) {
                return 'PDFs with scripts, embedded files or auto-run actions are not allowed.';
            }
            return '';
        }

        if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
            if (!class_exists('ZipArchive')) {
                return 'Office documents cannot be verified on this server.';
            }
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return 'The document appears to be corrupted.';
            }
            $root = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'][$ext];
            $hasTypes = false;
            $hasRoot = false;
            $total = 0;
            $error = $zip->numFiles > 5000 ? 'The document has too many parts.' : '';
            for ($i = 0; $error === '' && $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $entry = (string)($st['name'] ?? '');
                $total += (int)($st['size'] ?? 0);
                if ($entry === '[Content_Types].xml') {
                    $hasTypes = true;
                    $ct = (string)$zip->getFromIndex($i);
                    if (stripos($ct, 'macroEnabled') !== false || stripos($ct, 'vbaProject') !== false) {
                        $error = 'Documents with macros are not allowed.';
                    }
                }
                if (str_starts_with($entry, $root)) {
                    $hasRoot = true;
                }
                if (preg_match('#vbaProject|vbaData|activeX|\.bin$|\.(exe|dll|js|vbs|ps1|bat|cmd|hta|scr|jar)$#i', $entry)) {
                    $error = 'Documents with macros or embedded programs are not allowed.';
                }
                if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                    $error = 'The document structure is invalid.';
                }
            }
            $zip->close();
            if ($error !== '') {
                return $error;
            }
            if (!$hasTypes || !$hasRoot) {
                return 'The file is not a valid ' . strtoupper($ext) . ' document.';
            }
            if ($total > 200 * 1024 * 1024) {
                return 'The document expands to an unsafe size.'; // zip bomb guard
            }
            return '';
        }

        if (in_array($ext, ['csv', 'txt'], true)) {
            if (str_contains($data, "\0")) {
                return 'The text file contains binary data.';
            }
            return '';
        }

        return 'This file type is not allowed.';
    }
}

if (!function_exists('staff_chat_store_upload')) {
    /**
     * Validate and store one uploaded attachment ($_FILES entry).
     * @return array{path:string,name:string,mime:string,size:int}|string  stored file info, or an error message
     */
    function staff_chat_store_upload(array $file): array|string
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return 'Files must be 10 MB or smaller.';
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return 'Upload failed. Please try again.';
        }
        $size = (int)filesize($tmp);
        if ($size <= 0) {
            return 'The file is empty.';
        }
        if ($size > staff_chat_max_upload_bytes()) {
            return 'Files must be 10 MB or smaller.';
        }

        $name = staff_chat_clean_filename((string)($file['name'] ?? ''));
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $types = staff_chat_attachment_types();
        if (!isset($types[$ext])) {
            return 'Only JPG, PNG, WEBP, GIF, PDF, DOCX, XLSX, PPTX, CSV and TXT files are allowed.';
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = (string)($finfo->file($tmp) ?: '');
        if (!in_array($detected, $types[$ext], true)) {
            return 'The file content does not match its extension.';
        }
        $problem = staff_chat_inspect_file($tmp, $ext);
        if ($problem !== '') {
            return $problem;
        }

        $dir = staff_chat_upload_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return 'Could not save the file.';
        }
        // Random name + fixed safe extension: the client's name never touches the filesystem.
        $stored = bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
            return 'Could not save the file.';
        }
        @chmod($dir . '/' . $stored, 0644);
        return ['path' => $stored, 'name' => $name, 'mime' => $types[$ext][0], 'size' => $size];
    }
}

if (!function_exists('staff_chat_stored_file')) {
    /** Absolute path of a stored attachment, or null if the stored name is not one we generated. */
    function staff_chat_stored_file(?string $stored): ?string
    {
        if ($stored === null || !preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $stored)) {
            return null;
        }
        $abs = staff_chat_upload_dir() . '/' . $stored;
        return is_file($abs) ? $abs : null;
    }
}
