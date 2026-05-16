<?php

namespace App\Services\ReproAi;

class ToolDispatcher
{
    private const TOOL_MAPPING = [
        'get_property' => ['PropertyTools', 'getProperty'],
        'get_portfolio_overview' => ['PropertyTools', 'getPortfolioOverview'],
        'get_listing' => ['ListingTools', 'getListing'],
        'update_listing_copy' => ['ListingTools', 'updateListingCopy'],
        'book_shoot' => ['BookingTools', 'bookShoot'],
        'get_shoot_details' => ['ShootManagementTools', 'getShootDetails'],
        'list_shoots' => ['ShootManagementTools', 'listShoots'],
        'reschedule_shoot' => ['ShootManagementTools', 'rescheduleShoot'],
        'cancel_shoot' => ['ShootManagementTools', 'cancelShoot'],
        'get_payment_status' => ['PaymentTools', 'getPaymentStatus'],
        'create_payment_link' => ['PaymentTools', 'createPaymentLink'],
        'get_dashboard_stats' => ['DashboardTools', 'getDashboardStats'],
        'update_shoot_status' => ['DashboardTools', 'updateShootStatus'],
        'get_availability' => ['AvailabilityTools', 'getAvailability'],
        'submit_ai_editing' => ['AiEditingTools', 'submitAiEditing'],
        'get_ai_editing_status' => ['AiEditingTools', 'getAiEditingStatus'],
        'get_editing_types' => ['AiEditingTools', 'getEditingTypes'],
        'verify_caller' => ['IdentityTools', 'verifyCaller'],
        'handoff_to_staff' => ['HandoffTools', 'handoffToStaff'],
        'transfer_to_staff' => ['HandoffTools', 'transferToStaff'],
    ];

    public function dispatch(string $toolName, array $params, array $context = []): array
    {
        if (!isset(self::TOOL_MAPPING[$toolName])) {
            throw new \InvalidArgumentException("Unknown tool: {$toolName}");
        }

        [$toolClass, $methodName] = self::TOOL_MAPPING[$toolName];
        $className = "App\\Services\\ReproAi\\Tools\\{$toolClass}";

        if (!class_exists($className)) {
            throw new \RuntimeException("Tool class not found: {$className}");
        }

        $toolHandler = app($className);

        if (!method_exists($toolHandler, $methodName)) {
            throw new \RuntimeException("Tool method not found: {$methodName} in {$className}");
        }

        return $toolHandler->$methodName($params, $context);
    }
}
