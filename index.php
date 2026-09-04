<?php
session_start(); // ابدأ الجلسة هنا مرة واحدة فقط
/**
 * XAMPP Laravel Projects Dashboard
 * Modified Version (Features Added + Same Design)
 */

// =====================================================
// Configuration - التكوين
// =====================================================
class Config
{
    const PROJECTS_PATH = 'C:\\xampp\\htdocs\\project';
    const VSCODE_PATH = 'C:\\Users\\%USERNAME%\\AppData\\Local\\Programs\\Microsoft VS Code\\Code.exe';
    const GIT_BASH_PATH = 'C:\\Program Files\\Git\\git-bash.exe';
    const ANTIGRAVITY_PATH = 'C:\\Users\\%USERNAME%\\AppData\\Local\\Programs\\Antigravity IDE\\Antigravity IDE.exe';
    const LOCALHOST_URL = 'http://localhost/project';
    const ALLOWED_ACTIONS = [
        'open_explorer', 'run_project', 'open_vscode', 'open_git', 'open_browser', 'open_root',
        'open_antigravity', 'list_files', 'create_folder', 'create_root_folder',
        'open_file_code', 'open_file_browser', 'open_file_antigravity'
    ];
}

// =====================================================
// Security Functions
// =====================================================
class Security
{
    public static function sanitizeProjectName($name)
    {
        $name = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $name);
        return trim($name);
    }
    public static function validateProjectDirectory($projectPath)
    {
        return is_dir($projectPath) && file_exists($projectPath);
    }
    /**
     * تعقيم مسار فرعي (داخل مشروع) لمنع الخروج خارج مجلد المشروع (..)
     */
    public static function sanitizeRelativePath($path)
    {
        $path = str_replace('\\', '/', (string)$path);
        $parts = array_filter(explode('/', $path), function ($p) {
            return $p !== '' && $p !== '.' && $p !== '..';
        });
        return implode('\\', $parts);
    }
    /**
     * التأكد أن المسار الفعلي (بعد realpath) لا يزال داخل مسار الأصل المسموح به
     */
    public static function isPathInside($basePath, $targetPath)
    {
        $realBase = realpath($basePath);
        $realTarget = realpath($targetPath);
        if ($realBase === false || $realTarget === false) {
            return false;
        }
        return strpos($realTarget, $realBase) === 0;
    }
    public static function generateToken()
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    public static function verifyToken($token)
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// =====================================================
// Project Manager
// =====================================================
class ProjectManager
{
    private $projectsPath;
    private $response = ['success' => false, 'message' => ''];

    public function __construct($projectsPath)
    {
        $this->projectsPath = rtrim($projectsPath, '\\');
        if (!is_dir($this->projectsPath)) {
            mkdir($this->projectsPath, 0777, true);
        }
    }

    /**
     * دالة جديدة لحساب الحجم وعدد الملفات
     */
    private function getDirStats($dir)
    {
        $size = 0;
        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $file) {
                // التعديل هنا: نتحقق أن العنصر ملف وليس مجلداً قبل زيادة العداد
                if ($file->isFile()) {
                    $count++;
                    $size += $file->getSize();
                }
            }
        } catch (Exception $e) {
            error_log("خطأ في قراءة المجلد: " . $e->getMessage());
        }
        return ['size' => $this->formatSize($size), 'count' => $count];
    }

    private function formatSize($bytes)
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    public function getProjects()
    {
        if (!is_dir($this->projectsPath)) return [];
        $projects = [];
        $dirs = array_filter(glob($this->projectsPath . '\\*'), 'is_dir');

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $isLaravel = $this->isLaravelProject($dir);

            // جلب الإحصائيات (الحجم والعدد)
            $stats = $this->getDirStats($dir);

            $projects[] = [
                'name' => $name,
                'path' => $dir,
                'is_laravel' => $isLaravel,
                'last_modified' => filemtime($dir),
                'size' => $stats['size'],        // جديد
                'count' => $stats['count']       // جديد
            ];
        }
        usort($projects, function ($a, $b) {
            return $b['last_modified'] - $a['last_modified'];
        });
        return $projects;
    }

    private function isLaravelProject($path)
    {
        return file_exists($path . '\\artisan') && file_exists($path . '\\composer.json');
    }

    public function executeAction($action, $projectName)
    {

        // إذا كان الطلب فتح المجلد الرئيسي
        if ($action === 'open_root') {
            $this->openExplorer($this->projectsPath);
            return $this->response;
        }

        $projectName = Security::sanitizeProjectName($projectName);
        $projectPath = $this->projectsPath . '\\' . $projectName;

        if (!Security::validateProjectDirectory($projectPath)) {
            $this->response = ['success' => false, 'message' => 'المشروع غير موجود'];
            return $this->response;
        }

        switch ($action) {
            case 'open_explorer':
                $this->openExplorer($projectPath);
                break;
            case 'run_project':
                $this->runProject($projectPath, $projectName);
                break;
            case 'open_vscode':
                $this->openVSCode($projectPath);
                break;
            case 'open_git':
                $this->openGitBash($projectPath);
                break;
            case 'open_browser':
                $this->openInBrowser($projectName);
                break;
            default:
                $this->response = ['success' => false, 'message' => 'إجراء غير معروف'];
        }
        return $this->response;
    }

    private function openExplorer($path)
    {
        $realPath = realpath($path);
        if ($realPath) {
            $command = 'start "" explorer.exe "' . $realPath . '"';
            shell_exec($command);
            $this->response = ['success' => true, 'message' => 'تم فتح المجلد بنجاح'];
        } else {
            $this->response = ['success' => false, 'message' => 'المسار غير موجود'];
        }
    }

    private function runProject($path, $name)
    {
        $path = str_replace('/', '\\', $path);
        $command = 'start "Laravel - ' . addslashes($name) . '" cmd /c "cd /d "' . addslashes($path) . '" && php artisan serve && pause"';
        shell_exec($command);
        $this->response = ['success' => true, 'message' => 'تم تشغيل الخادم'];
    }

    // private function openVSCode($path)
    // {
    //     $vscodePath = str_replace('%USERNAME%', getenv('USERNAME'), Config::VSCODE_PATH);
    //     $path = str_replace('/', '\\', $path);
    //     if (file_exists($vscodePath)) {
    //         $command = 'start "" "' . addslashes($vscodePath) . '" "' . addslashes($path) . '"';
    //         shell_exec($command);
    //         $this->response = ['success' => true, 'message' => 'تم فتح VS Code'];
    //     } else {
    //         $this->response = ['success' => false, 'message' => 'VS Code غير مثبت'];
    //     }
    // }

    // private function openVSCode($path)
    // {
    //     $vscodePath = str_replace('%USERNAME%', getenv('USERNAME'), Config::VSCODE_PATH);
    //     $path = str_replace('/', '\\', $path);

    //     if (file_exists($vscodePath)) {
    //         // إضافة /b و > nul لفتح البرنامج في الخلفية دون انتظار استجابة
    //         $command = 'start "" /b "' . addslashes($vscodePath) . '" "' . addslashes($path) . '" > nul 2>&1';
    //         pclose(popen($command, "r")); 

    //         $this->response = ['success' => true, 'message' => 'تم فتح VS Code بنجاح'];
    //     } else {
    //         $this->response = ['success' => false, 'message' => 'مسار VS Code غير صحيح'];
    //     }
    // }
    private function openVSCode($path)
    {
        $vscodePath = str_replace('%USERNAME%', getenv('USERNAME'), Config::VSCODE_PATH);
        $path = str_replace('/', '\\', $path);

        if (file_exists($vscodePath)) {
            // فتح نافذة جديدة مع مسار المشروع
            $command = 'start "" /b "' . $vscodePath . '" -n "' . $path . '" > nul 2>&1';
            pclose(popen($command, "r"));

            $this->response = ['success' => true, 'message' => 'تم فتح VS Code بنجاح'];
        } else {
            $this->response = ['success' => false, 'message' => 'مسار VS Code غير صحيح'];
        }
    }
    /**
     * دالة عامة لفتح محرر أكواد (VS Code / Antigravity) بمسار معين
     */
    private function launchEditor($editorExePath, $targetPath)
    {
        if (!file_exists($editorExePath)) {
            return false;
        }
        $command = 'start "" /b "' . $editorExePath . '" -n "' . $targetPath . '" > nul 2>&1';
        pclose(popen($command, "r"));
        return true;
    }

    /**
     * فتح مشروع (أو ملف داخله) باستخدام Antigravity IDE
     */
    public function openAntigravity($projectName, $relFilePath = '')
    {
        $projectName = Security::sanitizeProjectName($projectName);
        $projectPath = $this->projectsPath . '\\' . $projectName;

        if (!Security::validateProjectDirectory($projectPath)) {
            return ['success' => false, 'message' => 'المشروع غير موجود'];
        }

        $target = realpath($projectPath);

        if ($relFilePath !== '') {
            $relFilePath = Security::sanitizeRelativePath($relFilePath);
            $candidate = $projectPath . '\\' . $relFilePath;
            if (!Security::isPathInside($projectPath, $candidate)) {
                return ['success' => false, 'message' => 'مسار غير صالح'];
            }
            $target = realpath($candidate);
        }

        $antigravityPath = str_replace('%USERNAME%', getenv('USERNAME'), Config::ANTIGRAVITY_PATH);

        if ($this->launchEditor($antigravityPath, $target)) {
            return ['success' => true, 'message' => 'تم فتح Antigravity بنجاح'];
        }
        return ['success' => false, 'message' => 'مسار Antigravity غير صحيح، يرجى التحقق من الإعدادات (Config::ANTIGRAVITY_PATH)'];
    }

    /**
     * فتح ملف معين في VS Code
     */
    public function openFileCode($projectName, $relFilePath)
    {
        $projectName = Security::sanitizeProjectName($projectName);
        $projectPath = $this->projectsPath . '\\' . $projectName;

        if (!Security::validateProjectDirectory($projectPath)) {
            return ['success' => false, 'message' => 'المشروع غير موجود'];
        }

        $relFilePath = Security::sanitizeRelativePath($relFilePath);
        $filePath = $projectPath . ($relFilePath !== '' ? '\\' . $relFilePath : '');

        if (!Security::isPathInside($projectPath, $filePath) || !is_file(realpath($filePath))) {
            return ['success' => false, 'message' => 'الملف غير موجود أو المسار غير صالح'];
        }

        $vscodePath = str_replace('%USERNAME%', getenv('USERNAME'), Config::VSCODE_PATH);

        if ($this->launchEditor($vscodePath, realpath($filePath))) {
            return ['success' => true, 'message' => 'تم فتح الملف في VS Code'];
        }
        return ['success' => false, 'message' => 'مسار VS Code غير صحيح'];
    }

    /**
     * فتح ملف HTML مباشرة في المتصفح عبر رابط localhost
     */
    public function openFileBrowser($projectName, $relFilePath)
    {
        $projectName = Security::sanitizeProjectName($projectName);
        $projectPath = $this->projectsPath . '\\' . $projectName;

        if (!Security::validateProjectDirectory($projectPath)) {
            return ['success' => false, 'message' => 'المشروع غير موجود'];
        }

        $relFilePath = Security::sanitizeRelativePath($relFilePath);
        $filePath = $projectPath . ($relFilePath !== '' ? '\\' . $relFilePath : '');

        if (!Security::isPathInside($projectPath, $filePath) || !is_file(realpath($filePath))) {
            return ['success' => false, 'message' => 'الملف غير موجود أو المسار غير صالح'];
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm'])) {
            return ['success' => false, 'message' => 'يمكن فتح ملفات HTML فقط في المتصفح'];
        }

        $urlSegments = array_map('rawurlencode', explode('\\', $relFilePath));
        $url = Config::LOCALHOST_URL . '/' . rawurlencode($projectName) . '/' . implode('/', $urlSegments);

        $command = 'start "" "' . $url . '"';
        shell_exec($command);
        return ['success' => true, 'message' => 'تم فتح الملف في المتصفح'];
    }

    /**
     * سرد الملفات والمجلدات داخل مسار فرعي من المشروع
     */
    public function listFiles($projectName, $subPath = '')
    {
        $projectName = Security::sanitizeProjectName($projectName);
        $projectPath = $this->projectsPath . '\\' . $projectName;

        if (!Security::validateProjectDirectory($projectPath)) {
            return ['success' => false, 'message' => 'المشروع غير موجود'];
        }

        $subPath = Security::sanitizeRelativePath($subPath);
        $targetPath = $projectPath . ($subPath !== '' ? '\\' . $subPath : '');

        if (!Security::isPathInside($projectPath, $targetPath) || !is_dir(realpath($targetPath))) {
            return ['success' => false, 'message' => 'مسار غير صالح'];
        }

        $realTarget = realpath($targetPath);
        $items = [];

        foreach (scandir($realTarget) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $realTarget . DIRECTORY_SEPARATOR . $entry;
            $isDir = is_dir($full);
            $items[] = [
                'name' => $entry,
                'type' => $isDir ? 'dir' : 'file',
                'ext' => $isDir ? '' : strtolower(pathinfo($entry, PATHINFO_EXTENSION)),
                'size' => $isDir ? '' : $this->formatSize(filesize($full)),
            ];
        }

        usort($items, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'success' => true,
            'project' => $projectName,
            'path' => $subPath,
            'items' => $items,
        ];
    }

    /**
     * إنشاء مجلد جديد داخل مشروع (في أي مستوى فرعي)
     */
    public function createFolder($projectName, $subPath, $folderName)
    {
        $projectName = Security::sanitizeProjectName($projectName);
        $projectPath = $this->projectsPath . '\\' . $projectName;

        if (!Security::validateProjectDirectory($projectPath)) {
            return ['success' => false, 'message' => 'المشروع غير موجود'];
        }

        $subPath = Security::sanitizeRelativePath($subPath);
        $targetDir = $projectPath . ($subPath !== '' ? '\\' . $subPath : '');

        if (!Security::isPathInside($projectPath, $targetDir) || !is_dir(realpath($targetDir))) {
            return ['success' => false, 'message' => 'مسار غير صالح'];
        }

        $folderName = trim(Security::sanitizeProjectName($folderName));
        if ($folderName === '') {
            return ['success' => false, 'message' => 'يرجى إدخال اسم صالح للمجلد'];
        }

        $newFolderPath = realpath($targetDir) . DIRECTORY_SEPARATOR . $folderName;
        if (file_exists($newFolderPath)) {
            return ['success' => false, 'message' => 'يوجد مجلد بهذا الاسم بالفعل'];
        }

        if (mkdir($newFolderPath, 0777, true)) {
            return ['success' => true, 'message' => 'تم إنشاء المجلد بنجاح'];
        }
        return ['success' => false, 'message' => 'فشل إنشاء المجلد'];
    }

    /**
     * إنشاء مجلد مشروع جديد في المسار الرئيسي (خارج أي مشروع)
     */
    public function createRootFolder($folderName)
    {
        $folderName = trim(Security::sanitizeProjectName($folderName));
        if ($folderName === '') {
            return ['success' => false, 'message' => 'يرجى إدخال اسم صالح للمجلد'];
        }

        $newPath = $this->projectsPath . '\\' . $folderName;
        if (file_exists($newPath)) {
            return ['success' => false, 'message' => 'يوجد مجلد/مشروع بهذا الاسم بالفعل'];
        }

        if (mkdir($newPath, 0777, true)) {
            return ['success' => true, 'message' => 'تم إنشاء المجلد بنجاح'];
        }
        return ['success' => false, 'message' => 'فشل إنشاء المجلد'];
    }

    private function openGitBash($path)
    {
        $path = str_replace('\\', '/', $path);
        if (file_exists(Config::GIT_BASH_PATH)) {
            $command = 'start "" "' . Config::GIT_BASH_PATH . '" --cd="' . addslashes($path) . '"';
            shell_exec($command);
            $this->response = ['success' => true, 'message' => 'تم فتح Git Bash'];
        } else {
            $this->response = ['success' => false, 'message' => 'Git Bash غير مثبت'];
        }
    }

    // private function openInBrowser($projectName)
    // {
    //     $url = Config::LOCALHOST_URL . '/' . urlencode($projectName);
    //     $command = 'start "" "' . $url . '"';
    //     shell_exec($command);
    //     $this->response = ['success' => true, 'message' => 'تم فتح المتصفح'];
    // }
    private function openInBrowser($projectName)
    {
        // استخدم rawurlencode بدلاً من str_replace للتعامل مع جميع الرموز الخاصة
        $encodedProjectName = rawurlencode($projectName);
        $url = Config::LOCALHOST_URL . '/' . $encodedProjectName;
        $command = 'start "" "' . $url . '"';
        shell_exec($command);
        $this->response = ['success' => true, 'message' => 'تم فتح المتصفح'];
    }
}

// =====================================================
// Handle POST
// =====================================================
$response = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !Security::verifyToken($_POST['csrf_token'])) {
        $response = ['success' => false, 'message' => 'خطأ أمني'];
    } else {
        $action = $_POST['action'] ?? '';
        $projectName = $_POST['project_name'] ?? '';

        // السماح بتنفيذ الإجراء بدون اسم مشروع في حالة فتح المجلد الرئيسي
        if (in_array($action, Config::ALLOWED_ACTIONS)) {
            $manager = new ProjectManager(Config::PROJECTS_PATH);

            switch ($action) {
                case 'list_files':
                    $response = $manager->listFiles($projectName, $_POST['sub_path'] ?? '');
                    break;
                case 'create_folder':
                    $response = $manager->createFolder($projectName, $_POST['sub_path'] ?? '', $_POST['folder_name'] ?? '');
                    break;
                case 'create_root_folder':
                    $response = $manager->createRootFolder($_POST['folder_name'] ?? '');
                    break;
                case 'open_file_code':
                    $response = $manager->openFileCode($projectName, $_POST['file_path'] ?? '');
                    break;
                case 'open_file_browser':
                    $response = $manager->openFileBrowser($projectName, $_POST['file_path'] ?? '');
                    break;
                case 'open_antigravity':
                    $response = $manager->openAntigravity($projectName, '');
                    break;
                case 'open_file_antigravity':
                    $response = $manager->openAntigravity($projectName, $_POST['file_path'] ?? '');
                    break;
                default:
                    $response = $manager->executeAction($action, $projectName);
            }
        }
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

$manager = new ProjectManager(Config::PROJECTS_PATH);
$projects = $manager->getProjects();
$csrfToken = Security::generateToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>لوحة تحكم مشاريع Laravel | XAMPP Dashboard</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <!-- Favicon - الأيقونة -->
    <link rel="icon" type="image/ico" sizes="32x32" href="ico.ico">
    <link rel="icon" type="image/ico" sizes="16x16" href="ico.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="ico.ico">
    <!-- <link rel="manifest" href="site.webmanifest"> -->
    <meta name="msapplication-TileColor" content="#6366f1">
    <meta name="theme-color" content="#6366f1">
    <!-- هذا يستخدم أيقونة إيموجي صاروخ 🚀 كـ favicon. يمكنك تغيير الإيموجي لأي شيء تريده مثل 📁 للمجلد أو ⚡ للتطبيق. -->
    <!-- <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚀</text></svg>"> -->
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* =====================================================
           CSS Variables - المتغيرات
           ===================================================== */
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #ec4899;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #0f172a;
            --dark-light: #1e293b;
            --dark-lighter: #334155;
            --light: #f1f5f9;
            --white: #ffffff;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --border: rgba(255, 255, 255, 0.1);
            --shadow: rgba(0, 0, 0, 0.3);
            --shadow-lg: rgba(0, 0, 0, 0.5);
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --radius: 16px;
            --radius-sm: 12px;
            --radius-lg: 24px;
        }

        /* =====================================================
   Custom Scrollbar - تخصيص شريط التمرير
   ===================================================== */
        /* عرض شريط التمرير */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        /* الخلفية الخاصة بمسار الشريط */
        ::-webkit-scrollbar-track {
            background: var(--dark);
            border-left: 1px solid var(--border);
        }

        /* المقبض (الجزء المتحرك) */
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
            border-radius: 10px;
            border: 2px solid var(--dark);
            /* يعطي تأثير مسافة بين المقبض والحواف */
        }

        /* لون المقبض عند تمرير الماوس فوقه */
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, var(--primary-dark), var(--secondary));
            box-shadow: inset 0 0 6px rgba(0, 0, 0, 0.3);
        }

        /* تخصيص شريط التمرير لمتصفح فايرفوكس */
        * {
            scrollbar-width: thin;
            scrollbar-color: var(--primary) var(--dark);
        }

        /* =====================================================
           Reset & Base Styles - إعادة تعيين الأنماط
           ===================================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background: var(--dark);
            color: var(--text);
            line-height: 1.7;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* Quick Actions + Search */
        .top-bar {
            width: 100%;
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 40px;
            transition: all 0.5s ease;
            /* تأكد من عدم وجود margin-bottom ضخم هنا يؤثر على الـ quick-actions */
            margin-bottom: 0 !important;
        }

        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .quick-btn {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            color: #fff;
            padding: 12px 24px;
            border-radius: var(--radius);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: 0.3s;
            font-weight: 600;
        }

        .quick-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-3px);
        }

        .quick-btn.phpmyadmin {
            background: linear-gradient(135deg, #f97316, #ea580c);
            border: none;
        }

        .quick-btn.root-folder {
            background: linear-gradient(135deg, var(--info), #2563eb);
            border: none;
        }

        .quick-btn.root-folder:hover {
            background: linear-gradient(135deg, var(--info), #1849b2);
            border: none;
        }

        .quick-btn.new-project {
            background: linear-gradient(135deg, var(--success), #059669);
            border: none;
        }

        .quick-btn.new-project:hover {
            background: linear-gradient(135deg, var(--success), #047857);
        }

        /* Search Input Styling */
        .search-wrapper {
            position: relative;
            width: 100%;
            max-width: 500px;
        }

        .search-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            padding: 15px 50px 15px 20px;
            border-radius: var(--radius);
            color: #fff;
            font-family: 'Cairo';
            font-size: 1rem;
            transition: 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.1);
            box-shadow: 0 0 15px rgba(99, 102, 241, 0.3);
        }

        .search-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
        }

        /* New: Project Details Section */
        .project-details {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .detail-item {
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-item span {
            color: var(--text);
            font-weight: 600;
        }

        .detail-item i {
            color: var(--primary);
            width: 16px;
            text-align: center;
        }

        /* =====================================================
           Animated Background - الخلفية المتحركة
           ===================================================== */
        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }

        .animated-bg::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background:
                radial-gradient(circle at 20% 50%, rgba(99, 102, 241, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(236, 72, 153, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(16, 185, 129, 0.1) 0%, transparent 50%);
            animation: backgroundMove 30s ease infinite;
        }

        @keyframes backgroundMove {

            0%,
            100% {
                transform: translate(0, 0) rotate(0deg);
            }

            33% {
                transform: translate(-5%, 5%) rotate(120deg);
            }

            66% {
                transform: translate(5%, -5%) rotate(240deg);
            }
        }

        .grid-pattern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: 1;
            pointer-events: none;
        }

        /* =====================================================
           Container - الحاوية الرئيسية
           ===================================================== */
        .container {
            position: relative;
            z-index: 2;
            max-width: 1400px;
            margin: 0 auto;
            padding: 100px 20px;
        }

        /* =====================================================
           Header - الترويسة
           ===================================================== */
        .header {
            text-align: center;
            margin-bottom: 60px;
            animation: fadeInDown 0.8s ease;
        }

        .header-title {
            font-size: clamp(2rem, 5vw, 3.5rem);
            font-weight: 900;
            background: linear-gradient(135deg, var(--primary), var(--secondary), var(--success));
            background-size: 200% 200%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 15px;
            animation: gradientShift 5s ease infinite;
        }

        @keyframes gradientShift {

            0%,
            100% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }
        }

        .header-subtitle {
            font-size: 1.1rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        .header-stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .stat-item {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            padding: 15px 30px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .stat-item:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.08);
        }

        .stat-number {
            direction: ltr;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            display: block;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 5px;
        }

        /* =====================================================
           Quick Actions - الإجراءات السريعة
           ===================================================== */
        /* الحالة الافتراضية - تأكد من تعديل هذا الجزء في كودك */
        .quick-actions {
            position: fixed;
            top: 30px;
            z-index: 1000;
            display: flex;
            gap: 15px;
            padding: 10px 20px;
            border-radius: var(--radius);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            background: rgba(255, 255, 255, 0);
            border: 1px solid transparent;
            width: 100%;
            align-items: center;
        }

        /* الحالة الزجاجية عند التمرير */
        .quick-actions.scrolled {
            top: 0;
            left: 0;
            width: 100%;
            border-radius: 0;
            /* تحول من حواف دائرية لشريط كامل العرض */
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-bottom: 1px solid var(--border);
            padding: 15px 50px;
            /* زيادة الهوامش الجانبية قليلاً لتناسب عرض الشاشة */
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }

        .scrolled .top-bar {
            width: 100%;
            align-items: center;
            justify-content: center;
        }


        .quick-btn {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            color: var(--white);
            padding: 12px 24px;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .quick-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px var(--shadow);
        }

        .quick-btn i {
            font-size: 1.1rem;
        }

        .quick-btn.phpmyadmin {
            background: linear-gradient(135deg, #f97316, #ea580c);
            border: none;
        }

        .quick-btn.phpmyadmin:hover {
            background: linear-gradient(135deg, #ea580c, #c2410c);
        }

        /* =====================================================
           Projects Grid - شبكة المشاريع
           ===================================================== */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 30px;
            animation: fadeInUp 1s ease;
        }

        /* =====================================================
           Project Card - بطاقة المشروع
           ===================================================== */
        .project-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 30px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .project-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-1);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.5s ease;
        }

        .project-card:hover::before {
            transform: scaleX(1);
        }

        .project-card:hover {
            transform: translateY(-8px);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 20px 60px var(--shadow-lg);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .project-card:nth-child(4n+1)::before {
            background: var(--gradient-1);
        }

        .project-card:nth-child(4n+2)::before {
            background: var(--gradient-2);
        }

        .project-card:nth-child(4n+3)::before {
            background: var(--gradient-3);
        }

        .project-card:nth-child(4n+4)::before {
            background: var(--gradient-4);
        }

        .project-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
        }

        .project-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-sm);
            background: var(--gradient-1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: var(--white);
            flex-shrink: 0;
        }

        .project-card:nth-child(4n+1) .project-icon {
            background: var(--gradient-1);
        }

        .project-card:nth-child(4n+2) .project-icon {
            background: var(--gradient-2);
        }

        .project-card:nth-child(4n+3) .project-icon {
            background: var(--gradient-3);
        }

        .project-card:nth-child(4n+4) .project-icon {
            background: var(--gradient-4);
        }

        .project-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            color: var(--success);
            background: rgba(16, 185, 129, 0.1);
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 2s ease infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .project-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 8px;
            word-break: break-word;
        }

        .project-path {
            font-size: 0.85rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 20px;
        }

        .project-path i {
            font-size: 0.9rem;
        }

        /* =====================================================
           Action Buttons - أزرار الإجراءات
           ===================================================== */
        .actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .actions .browser-btn-full {
            grid-column: 1 / -1;
        }

        .action-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--border);
            color: var(--white);
            padding: 14px 20px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .action-btn:hover::before {
            width: 100%;
            height: 300px;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px var(--shadow);
        }

        .action-btn:active {
            transform: translateY(0);
        }

        .action-btn i {
            font-size: 1.1rem;
            z-index: 1;
        }

        .action-btn span {
            z-index: 1;
        }

        .action-btn.explorer {
            background: linear-gradient(135deg, var(--success), #059669);
            border: none;
        }

        .action-btn.run {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
        }

        .action-btn.vscode {
            background: linear-gradient(135deg, var(--info), #2563eb);
            border: none;
        }

        .action-btn.git {
            background: linear-gradient(135deg, var(--warning), #d97706);
            border: none;
        }

        .action-btn.browser {
            background: linear-gradient(135deg, var(--secondary), #db2777);
            border: none;
        }

        .action-btn.antigravity {
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            border: none;
        }

        .action-btn.files {
            background: linear-gradient(135deg, #06b6d4, #0e7490);
            border: none;
        }

        /* =====================================================
           Files Modal - نافذة عرض الملفات
           ===================================================== */
        .files-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.75);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            padding: 20px;
        }

        .files-modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .files-modal {
            width: 100%;
            max-width: 720px;
            max-height: 82vh;
            background: var(--dark-light);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: 0 25px 60px var(--shadow-lg);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transform: translateY(20px) scale(0.98);
            transition: var(--transition);
        }

        .files-modal-overlay.show .files-modal {
            transform: translateY(0) scale(1);
        }

        .files-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.03);
        }

        .files-modal-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.15rem;
            color: var(--white);
        }

        .files-modal-close {
            background: rgba(255, 255, 255, 0.08);
            border: none;
            color: var(--text);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
        }

        .files-modal-close:hover {
            background: var(--danger);
            color: var(--white);
        }

        .files-modal-toolbar {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .files-breadcrumb {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .files-breadcrumb .crumb {
            cursor: pointer;
            color: var(--text);
            padding: 4px 8px;
            border-radius: 6px;
            transition: var(--transition);
        }

        .files-breadcrumb .crumb:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--primary);
        }

        .files-breadcrumb .crumb-sep {
            opacity: 0.5;
        }

        .files-new-folder {
            display: flex;
            gap: 10px;
        }

        .files-new-folder input {
            flex: 1;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            color: var(--white);
            font-family: 'Cairo', sans-serif;
            outline: none;
        }

        .files-new-folder input:focus {
            border-color: var(--primary);
        }

        .files-new-folder button {
            background: linear-gradient(135deg, var(--success), #059669);
            border: none;
            color: var(--white);
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: var(--transition);
        }

        .files-new-folder button:hover {
            transform: translateY(-2px);
        }

        .files-list {
            overflow-y: auto;
            padding: 10px 16px 20px;
        }

        .files-loading,
        .files-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .file-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 12px;
            border-radius: var(--radius-sm);
            transition: var(--transition);
            gap: 10px;
        }

        .file-row:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .file-icon {
            font-size: 1.15rem;
            color: var(--info);
            width: 22px;
            text-align: center;
            flex-shrink: 0;
        }

        .file-icon.dir-icon {
            color: var(--warning);
        }

        .file-name {
            color: var(--text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-size {
            color: var(--text-muted);
            font-size: 0.8rem;
            direction: ltr;
            flex-shrink: 0;
        }

        .file-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .file-action-btn {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.06);
            color: var(--white);
            cursor: pointer;
            transition: var(--transition);
        }

        .file-action-btn:hover {
            transform: translateY(-2px);
        }

        .file-action-btn.code:hover {
            background: linear-gradient(135deg, var(--info), #2563eb);
        }

        .file-action-btn.antigravity:hover {
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
        }

        .file-action-btn.browser:hover {
            background: linear-gradient(135deg, var(--secondary), #db2777);
        }

        /* =====================================================
           Notification Toast - إشعار التنبيه
           ===================================================== */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            padding: 20px 25px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 300px;
            transform: translateX(400px);
            opacity: 0;
            transition: var(--transition);
            z-index: 9999;
            box-shadow: 0 10px 40px var(--shadow-lg);
        }

        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }

        .toast.success {
            border-right: 4px solid var(--success);
        }

        .toast.error {
            border-right: 4px solid var(--danger);
        }

        .toast-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .toast.success .toast-icon {
            color: var(--success);
        }

        .toast.error .toast-icon {
            color: var(--danger);
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--white);
        }

        .toast-message {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* =====================================================
           Empty State - حالة عدم وجود مشاريع
           ===================================================== */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            animation: fadeIn 1s ease;
        }

        .empty-icon {
            font-size: 5rem;
            color: var(--text-muted);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 10px;
        }

        .empty-message {
            font-size: 1.1rem;
            color: var(--text-muted);
            max-width: 500px;
            margin: 0 auto;
        }

        /* =====================================================
           Loading Spinner - مؤشر التحميل
           ===================================================== */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--white);
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* =====================================================
           Animations - الحركات
           ===================================================== */
        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* =====================================================
           Responsive Design - التصميم المتجاوب
           ===================================================== */
        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
            }

            .header {
                margin-bottom: 40px;
            }

            .header-title {
                font-size: 2rem;
            }

            .header-stats {
                gap: 15px;
            }

            .stat-item {
                padding: 12px 20px;
            }

            .quick-actions {
                position: static;
                justify-content: center;
                margin-bottom: 30px;
                flex-wrap: wrap;
            }

            .projects-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .actions {
                grid-template-columns: 1fr;
            }

            .toast {
                right: 15px;
                left: 15px;
                min-width: auto;
            }
        }

        /* =====================================================
           Print Styles - أنماط الطباعة
           ===================================================== */
        @media print {

            .animated-bg,
            .quick-actions,
            .action-btn {
                display: none;
            }

            .project-card {
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <!-- Animated Background -->
    <div class="animated-bg"></div>
    <div class="grid-pattern"></div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <div class="top-bar">
            <div class="search-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="ابحث عن اسم المشروع...">
            </div>

            <a href="/phpmyadmin/" target="_blank" class="quick-btn phpmyadmin">
                <i class="fas fa-database"></i>
                <span>phpMyAdmin</span>
            </a>
            <button class="quick-btn root-folder" onclick="executeAction('open_root', '')">
                <i class="fas fa-folder-open"></i>
                <span>المجلد الرئيسي</span>
            </button>
            <button class="quick-btn new-project" onclick="createRootProject()">
                <i class="fas fa-folder-plus"></i>
                <span>مجلد/مشروع جديد</span>
            </button>
            <button class="quick-btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i>
                <span>تحديث</span>
            </button>
        </div>
    </div>

    <!-- Main Container -->
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1 class="header-title">
                <i class="fas fa-code"></i>
                لوحة تحكم مشاريع
            </h1>
            <p class="header-subtitle">إدارة احترافية لجميع مشاريعك في مكان واحد</p>

            <div class="header-stats">
                <div class="stat-item">
                    <span class="stat-number"><?php echo count($projects); ?></span>
                    <span class="stat-label">إجمالي المشاريع</span>
                </div>
                <div class="stat-item">
                    <span
                        class="stat-number"><?php echo count(array_filter($projects, fn($p) => $p['is_laravel'])); ?></span>
                    <span class="stat-label">مشاريع Laravel</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo date('d M Y'); ?></span>
                    <span class="stat-label">تاريخ اليوم</span>
                </div>
            </div>
        </header>

        <!-- Projects Grid -->
        <?php if (empty($projects)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open empty-icon"></i>
                <h2 class="empty-title">لا توجد مشاريع</h2>
                <p class="empty-message">
                    لم يتم العثور على أي مشاريع في المجلد المحدد.<br>
                    تأكد من وجود مشاريع في المسار: <code><?php echo Config::PROJECTS_PATH; ?></code>
                </p>
            </div>
        <?php else: ?>
            <div class="projects-grid">
                <?php foreach ($projects as $index => $project): ?>
                    <div class="project-card" data-project="<?php echo htmlspecialchars($project['name']); ?>">
                        <div class="project-header">
                            <div class="project-icon">
                                <?php if ($project['is_laravel']): ?>
                                    <i class="fab fa-laravel"></i>
                                <?php else: ?>
                                    <i class="fas fa-code"></i> <?php endif; ?>
                            </div>
                            <?php if ($project['is_laravel']): ?>
                                <div class="project-status">Laravel</div>
                            <?php else: ?>
                                <div class="project-status not-laravel">native</div>
                            <?php endif; ?>
                        </div>

                        <h3 class="project-title"><?php echo htmlspecialchars($project['name']); ?></h3>
                        <p class="project-path">
                            <i class="fas fa-folder"></i>
                            <span><?php echo htmlspecialchars(basename($project['path'])); ?></span>
                        </p>
                        <div class="project-details">
                            <div class="detail-item">
                                <i class="fas fa-hdd"></i>
                                <span dir="ltr"><?php echo $project['size']; ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-file"></i>
                                <span><?php echo $project['count']; ?> ملف</span>
                            </div>
                            <div class="detail-item" style="grid-column: 1/-1; margin-top: 5px;">
                                <i class="fas fa-clock"></i>
                                <span dir="ltr"><?php echo date('Y-m-d H:i', $project['last_modified']); ?></span>
                            </div>
                        </div>
                        <div class="actions">
                            <?php if ($project['is_laravel']): ?>
                                <button class="action-btn run browser-btn-full"
                                    onclick="executeAction('run_project', '<?php echo htmlspecialchars($project['name']); ?>')">
                                    <i class="fas fa-play"></i> <span>تشغيل</span>
                                </button>
                            <?php else: ?>
                                <button class="action-btn browser browser-btn-full"
                                    onclick="executeAction('open_browser', '<?php echo htmlspecialchars($project['name']); ?>')">
                                    <i class="fas fa-globe"></i>
                                    <span>فتح في المتصفح</span>
                                </button>
                            <?php endif; ?>

                            <button class="action-btn files browser-btn-full"
                                onclick="openFilesModal('<?php echo htmlspecialchars($project['name'], ENT_QUOTES); ?>')">
                                <i class="fas fa-folder-tree"></i>
                                <span>عرض الملفات</span>
                            </button>

                            <button class="action-btn explorer"
                                onclick="executeAction('open_explorer', '<?php echo htmlspecialchars($project['name']); ?>')">
                                <i class="fas fa-folder-open"></i>
                                <span>فتح المجلد</span>
                            </button>

                            <button class="action-btn vscode"
                                onclick="executeAction('open_vscode', '<?php echo htmlspecialchars($project['name']); ?>')">
                                <i class="fas fa-code"></i>
                                <span>VS Code</span>
                            </button>

                            <button class="action-btn antigravity"
                                onclick="executeAction('open_antigravity', '<?php echo htmlspecialchars($project['name']); ?>')">
                                <i class="fas fa-meteor"></i>
                                <span>Antigravity</span>
                            </button>

                            <!-- <button class="action-btn git"
                                onclick="executeAction('open_git', '<?php echo htmlspecialchars($project['name']); ?>')">
                                <i class="fab fa-git-alt"></i> <span>Git</span>
                            </button> -->
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Files Modal -->
    <div id="filesModalOverlay" class="files-modal-overlay">
        <div class="files-modal">
            <div class="files-modal-header">
                <h3><i class="fas fa-folder-tree"></i> <span id="filesModalTitle">ملفات المشروع</span></h3>
                <button class="files-modal-close" onclick="closeFilesModal()" title="إغلاق">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="files-modal-toolbar">
                <div class="files-breadcrumb" id="filesBreadcrumb"></div>
                <div class="files-new-folder">
                    <input type="text" id="newFolderInput" placeholder="اسم المجلد الجديد...">
                    <button onclick="createNewFolder()">
                        <i class="fas fa-folder-plus"></i>
                        <span>إنشاء مجلد</span>
                    </button>
                </div>
            </div>
            <div class="files-list" id="filesList">
                <div class="files-loading"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <i class="toast-icon fas fa-check-circle"></i>
        <div class="toast-content">
            <div class="toast-title">نجاح</div>
            <div class="toast-message">تم تنفيذ العملية بنجاح</div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        const csrfToken = '<?php echo $csrfToken; ?>';

        // 1. تصحيح وظيفة البحث المباشر
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const val = this.value.toLowerCase();
            // نبحث عن الكروت بناءً على السمات الصحيحة
            const cards = document.querySelectorAll('.project-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-project').toLowerCase();
                if (name.includes(val)) {
                    card.style.display = ''; // أو card.style.display = 'block' أو 'flex' حسب التصميم
                } else {
                    card.style.display = 'none';
                }
            });
        });
        // 2. تصحيح دالة إظهار التنبيهات
        function showToast(success, msg) {
            const toast = document.getElementById('toast');
            const toastTitle = toast.querySelector('.toast-title');
            const toastMsg = toast.querySelector('.toast-message');
            const toastIcon = toast.querySelector('.toast-icon');

            // تحديث المحتوى
            toastTitle.textContent = success ? 'نجاح' : 'خطأ';
            toastMsg.textContent = msg;

            // تحديث الشكل
            toast.className = `toast show ${success ? 'success' : 'error'}`;
            toastIcon.className = `toast-icon fas ${success ? 'fa-check-circle' : 'fa-exclamation-triangle'}`;

            // إخفاء التنبيه بعد 3 ثوانٍ
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // 3. دالة تنفيذ الأوامر (تأكد من وجودها كما هي)
        function executeAction(action, projectName) {
            const btn = event ? event.target.closest('button') : null;
            const originalContent = btn.innerHTML;

            if (action !== 'open_root') {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }

            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        action: action,
                        project_name: projectName,
                        csrf_token: csrfToken
                    })
                })
                .then(res => res.json())
                .then(data => {
                    showToast(data.success, data.message);
                    if (action !== 'open_root') {
                        setTimeout(() => {
                            btn.disabled = false;
                            btn.innerHTML = originalContent;
                        }, 500);
                    }
                })
                .catch(() => {
                    showToast(false, 'حدث خطأ غير متوقع');
                    if (action !== 'open_root') {
                        btn.disabled = false;
                        btn.innerHTML = originalContent;
                    }
                });
        }

        // إضافة تأثير التموج (Ripple Effect)
        document.querySelectorAll('.action-btn, .quick-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                ripple.style.cssText = `
            position: absolute; 
            border-radius: 50%; 
            background: rgba(255, 255, 255, 0.4);
            width: 100px; 
            height: 100px; 
            margin-left: -50px; 
            margin-top: -50px;
            left: ${e.offsetX}px; 
            top: ${e.offsetY}px; 
            pointer-events: none;
            animation: ripple 0.6s linear;
        `;
                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);
                setTimeout(() => ripple.remove(), 600);
            });
        });
        window.addEventListener('scroll', function() {
            const quickActions = document.querySelector('.quick-actions');

            // سيبدأ التحول بعد تمرير 20 بكسل فقط ليكون الاستهلال فورياً وناعماً
            if (window.scrollY > 20) {
                quickActions.classList.add('scrolled');
            } else {
                quickActions.classList.remove('scrolled');
            }
        });

        // =====================================================
        // Files Modal - نافذة عرض الملفات ومتصفح الملفات
        // =====================================================
        let currentFilesProject = '';
        let currentFilesPath = '';

        function openFilesModal(projectName) {
            currentFilesProject = projectName;
            currentFilesPath = '';
            document.getElementById('filesModalTitle').textContent = 'ملفات: ' + projectName;
            document.getElementById('newFolderInput').value = '';
            document.getElementById('filesModalOverlay').classList.add('show');
            document.body.style.overflow = 'hidden';
            loadFiles();
        }

        function closeFilesModal() {
            document.getElementById('filesModalOverlay').classList.remove('show');
            document.body.style.overflow = '';
        }

        document.getElementById('filesModalOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeFilesModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeFilesModal();
        });

        function apiRequest(params) {
            return fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        csrf_token: csrfToken,
                        ...params
                    })
                })
                .then(res => res.json());
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function loadFiles() {
            const list = document.getElementById('filesList');
            list.innerHTML = '<div class="files-loading"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</div>';

            apiRequest({
                    action: 'list_files',
                    project_name: currentFilesProject,
                    sub_path: currentFilesPath
                })
                .then(data => {
                    if (!data || !data.success) {
                        list.innerHTML = '<div class="files-empty"><i class="fas fa-triangle-exclamation"></i><br>' +
                            escapeHtml((data && data.message) || 'تعذر تحميل الملفات') + '</div>';
                        return;
                    }
                    renderBreadcrumb(data.path);
                    renderFilesList(data.items);
                })
                .catch(() => {
                    list.innerHTML = '<div class="files-empty">حدث خطأ أثناء تحميل الملفات</div>';
                });
        }

        function renderBreadcrumb(path) {
            const bc = document.getElementById('filesBreadcrumb');
            bc.innerHTML = '';

            const rootSpan = document.createElement('span');
            rootSpan.className = 'crumb';
            rootSpan.innerHTML = '<i class="fas fa-house"></i> ' + escapeHtml(currentFilesProject);
            rootSpan.onclick = () => {
                currentFilesPath = '';
                loadFiles();
            };
            bc.appendChild(rootSpan);

            if (path) {
                const parts = path.split('\\').filter(Boolean);
                let acc = [];
                parts.forEach(part => {
                    acc.push(part);
                    const sep = document.createElement('span');
                    sep.className = 'crumb-sep';
                    sep.textContent = '/';
                    bc.appendChild(sep);

                    const span = document.createElement('span');
                    span.className = 'crumb';
                    span.textContent = part;
                    const fullPath = acc.join('\\');
                    span.onclick = () => {
                        currentFilesPath = fullPath;
                        loadFiles();
                    };
                    bc.appendChild(span);
                });
            }
        }

        function fileIconClass(item) {
            if (item.type === 'dir') return 'fas fa-folder';
            const map = {
                html: 'fab fa-html5',
                htm: 'fab fa-html5',
                css: 'fab fa-css3-alt',
                scss: 'fab fa-sass',
                js: 'fab fa-js',
                mjs: 'fab fa-js',
                php: 'fab fa-php',
                json: 'fas fa-file-code',
                md: 'fab fa-markdown',
                png: 'fas fa-file-image',
                jpg: 'fas fa-file-image',
                jpeg: 'fas fa-file-image',
                svg: 'fas fa-file-image',
                gif: 'fas fa-file-image',
                webp: 'fas fa-file-image',
                zip: 'fas fa-file-zipper',
                rar: 'fas fa-file-zipper',
                pdf: 'fas fa-file-pdf',
                sql: 'fas fa-database',
                env: 'fas fa-gear',
                txt: 'fas fa-file-lines',
            };
            return map[item.ext] || 'fas fa-file';
        }

        function renderFilesList(items) {
            const list = document.getElementById('filesList');
            list.innerHTML = '';

            if (!items || !items.length) {
                list.innerHTML = '<div class="files-empty"><i class="fas fa-inbox"></i><br>هذا المجلد فارغ</div>';
                return;
            }

            items.forEach(item => {
                const row = document.createElement('div');
                row.className = 'file-row';

                const info = document.createElement('div');
                info.className = 'file-info';
                info.innerHTML = `<i class="${fileIconClass(item)} file-icon ${item.type === 'dir' ? 'dir-icon' : ''}"></i>
                    <span class="file-name">${escapeHtml(item.name)}</span>
                    ${item.size ? '<span class="file-size">' + item.size + '</span>' : ''}`;

                if (item.type === 'dir') {
                    info.style.cursor = 'pointer';
                    info.onclick = () => {
                        currentFilesPath = currentFilesPath ? currentFilesPath + '\\' + item.name : item.name;
                        loadFiles();
                    };
                }

                const actions = document.createElement('div');
                actions.className = 'file-actions';

                if (item.type === 'file') {
                    const relPath = currentFilesPath ? currentFilesPath + '\\' + item.name : item.name;

                    const codeBtn = document.createElement('button');
                    codeBtn.className = 'file-action-btn code';
                    codeBtn.title = 'فتح في محرر الأكواد (VS Code)';
                    codeBtn.innerHTML = '<i class="fas fa-code"></i>';
                    codeBtn.onclick = (e) => {
                        e.stopPropagation();
                        runFileAction('open_file_code', relPath, codeBtn);
                    };
                    actions.appendChild(codeBtn);

                    const antigravityBtn = document.createElement('button');
                    antigravityBtn.className = 'file-action-btn antigravity';
                    antigravityBtn.title = 'فتح بـ Antigravity IDE';
                    antigravityBtn.innerHTML = '<i class="fas fa-meteor"></i>';
                    antigravityBtn.onclick = (e) => {
                        e.stopPropagation();
                        runFileAction('open_file_antigravity', relPath, antigravityBtn);
                    };
                    actions.appendChild(antigravityBtn);

                    if (item.ext === 'html' || item.ext === 'htm') {
                        const browserBtn = document.createElement('button');
                        browserBtn.className = 'file-action-btn browser';
                        browserBtn.title = 'فتح في المتصفح';
                        browserBtn.innerHTML = '<i class="fas fa-globe"></i>';
                        browserBtn.onclick = (e) => {
                            e.stopPropagation();
                            runFileAction('open_file_browser', relPath, browserBtn);
                        };
                        actions.appendChild(browserBtn);
                    }
                }

                row.appendChild(info);
                row.appendChild(actions);
                list.appendChild(row);
            });
        }

        function runFileAction(action, relPath, btn) {
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            apiRequest({
                    action: action,
                    project_name: currentFilesProject,
                    file_path: relPath
                })
                .then(data => {
                    showToast(data.success, data.message);
                    btn.disabled = false;
                    btn.innerHTML = original;
                })
                .catch(() => {
                    showToast(false, 'حدث خطأ غير متوقع');
                    btn.disabled = false;
                    btn.innerHTML = original;
                });
        }

        function createNewFolder() {
            const input = document.getElementById('newFolderInput');
            const name = input.value.trim();
            if (!name) {
                showToast(false, 'يرجى إدخال اسم المجلد أولاً');
                return;
            }

            apiRequest({
                    action: 'create_folder',
                    project_name: currentFilesProject,
                    sub_path: currentFilesPath,
                    folder_name: name
                })
                .then(data => {
                    showToast(data.success, data.message);
                    if (data.success) {
                        input.value = '';
                        loadFiles();
                    }
                })
                .catch(() => showToast(false, 'حدث خطأ غير متوقع'));
        }

        // إنشاء مجلد/مشروع جديد في المسار الرئيسي للمشاريع
        function createRootProject() {
            const name = prompt('أدخل اسم المجلد/المشروع الجديد:');
            if (!name || !name.trim()) return;

            apiRequest({
                    action: 'create_root_folder',
                    folder_name: name.trim()
                })
                .then(data => {
                    showToast(data.success, data.message);
                    if (data.success) {
                        setTimeout(() => location.reload(), 800);
                    }
                })
                .catch(() => showToast(false, 'حدث خطأ غير متوقع'));
        }
    </script>
</body>

</html>