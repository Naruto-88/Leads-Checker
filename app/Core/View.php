<?php
namespace App\Core;

class View
{
    public static function render(string $template, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $templatePath = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!file_exists($templatePath)) {
            http_response_code(500);
            echo 'View not found: ' . htmlspecialchars($template);
            return;
        }
        include BASE_PATH . '/app/Views/partials/header.php';
        include $templatePath;
        include BASE_PATH . '/app/Views/partials/footer.php';
    }

    public static function partial(string $template, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $templatePath = BASE_PATH . '/app/Views/' . $template . '.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }
}

