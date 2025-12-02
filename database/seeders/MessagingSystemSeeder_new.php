<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\AutomationRule;
use Illuminate\Database\Seeder;

class MessagingSystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSystemTemplates();
        $this->seedRequiredAutomations();
    }

    private function seedSystemTemplates(): void
    {
        $templates = [
            // 1. New Account Created
            [
                'channel' => 'EMAIL',
                'name' => 'New Account Created',
                'slug' => 'account-created',
                'description' => 'Welcome email sent when a new account is created',
                'category' => 'ACCOUNT',
                'subject' => 'Welcome to REPRO HQ - Your Account is Ready!',
                'body_html' => $this->getAccountCreatedTemplate(),
                'body_text' => 'Welcome to REPRO HQ! Your account has been created successfully.',
                'variables_json' => ['client_name', 'client_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 2. Shoot Scheduled
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Scheduled',
                'slug' => 'shoot-scheduled',
                'description' => 'Confirmation email when shoot is scheduled',
                'category' => 'BOOKING',
                'subject' => 'Shoot Scheduled - {{shoot_date}}',
                'body_html' => $this->getShootScheduledTemplate(),
                'body_text' => 'Your shoot has been scheduled for {{shoot_date}} at {{shoot_time}}.',
                'variables_json' => ['client_name', 'shoot_date', 'shoot_time', 'shoot_address', 'photographer_name'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 3. Shoot Requested
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Requested',
                'slug' => 'shoot-requested',
                'description' => 'Confirmation that shoot request has been received',
                'category' => 'BOOKING',
                'subject' => 'Shoot Request Received - We\'ll Confirm Shortly',
                'body_html' => $this->getShootRequestedTemplate(),
                'body_text' => 'We have received your shoot request and will confirm availability shortly.',
                'variables_json' => ['client_name', 'shoot_date', 'shoot_time', 'shoot_address'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 4. Shoot Request Approved
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Request Approved',
                'slug' => 'shoot-request-approved',
                'description' => 'Shoot request has been approved',
                'category' => 'BOOKING',
                'subject' => 'Great News! Your Shoot is Confirmed',
                'body_html' => $this->getShootRequestApprovedTemplate(),
                'body_text' => 'Your shoot request has been approved and confirmed.',
                'variables_json' => ['client_name', 'shoot_date', 'shoot_time', 'shoot_address', 'photographer_name'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 5. Shoot Request Modified/Verified
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Request Modified & Approved',
                'slug' => 'shoot-request-modified',
                'description' => 'Shoot request modified and approved with new details',
                'category' => 'BOOKING',
                'subject' => 'Shoot Details Updated & Confirmed',
                'body_html' => $this->getShootRequestModifiedTemplate(),
                'body_text' => 'Your shoot request has been updated with new details and confirmed.',
                'variables_json' => ['client_name', 'shoot_date', 'shoot_time', 'shoot_address', 'photographer_name', 'changes_made'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 6. Shoot Request Declined
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Request Declined',
                'slug' => 'shoot-request-declined',
                'description' => 'Shoot request could not be accommodated',
                'category' => 'BOOKING',
                'subject' => 'Regarding Your Shoot Request',
                'body_html' => $this->getShootRequestDeclinedTemplate(),
                'body_text' => 'We apologize but we cannot accommodate your shoot request at this time.',
                'variables_json' => ['client_name', 'shoot_date', 'decline_reason'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 7. Shoot Reminder
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Reminder',
                'slug' => 'shoot-reminder',
                'description' => 'Reminder before scheduled shoot',
                'category' => 'REMINDER',
                'subject' => 'Reminder: Upcoming Shoot Tomorrow',
                'body_html' => $this->getShootReminderTemplate(),
                'body_text' => 'This is a reminder that your shoot is scheduled for tomorrow.',
                'variables_json' => ['client_name', 'shoot_date', 'shoot_time', 'shoot_address', 'photographer_name'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 8. Shoot Updated
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Updated',
                'slug' => 'shoot-updated',
                'description' => 'Shoot details have been updated',
                'category' => 'BOOKING',
                'subject' => 'Shoot Details Updated',
                'body_html' => $this->getShootUpdatedTemplate(),
                'body_text' => 'Your shoot details have been updated.',
                'variables_json' => ['client_name', 'shoot_date', 'shoot_time', 'shoot_address', 'changes_made'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 9. Shoot Ready (Photos Ready)
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Ready - Photos Available',
                'slug' => 'shoot-ready',
                'description' => 'Photos are processed and ready for download',
                'category' => 'GENERAL',
                'subject' => 'Your Photos Are Ready! 📸',
                'body_html' => $this->getShootReadyTemplate(),
                'body_text' => 'Great news! Your photos are ready for download.',
                'variables_json' => ['client_name', 'shoot_date', 'photo_count', 'download_link'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 10. Payment Due Reminder
            [
                'channel' => 'EMAIL',
                'name' => 'Payment Due Reminder',
                'slug' => 'payment-due-reminder',
                'description' => 'Reminder for outstanding payment',
                'category' => 'PAYMENT',
                'subject' => 'Payment Reminder - Invoice {{invoice_number}}',
                'body_html' => $this->getPaymentDueReminderTemplate(),
                'body_text' => 'This is a friendly reminder about your outstanding invoice.',
                'variables_json' => ['client_name', 'invoice_number', 'amount_due', 'due_date', 'payment_link'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 11. Thank You For Payment
            [
                'channel' => 'EMAIL',
                'name' => 'Thank You For Your Payment',
                'slug' => 'payment-thank-you',
                'description' => 'Payment received confirmation',
                'category' => 'PAYMENT',
                'subject' => 'Payment Received - Thank You!',
                'body_html' => $this->getPaymentThankYouTemplate(),
                'body_text' => 'Thank you for your payment!',
                'variables_json' => ['client_name', 'payment_amount', 'invoice_number', 'payment_date'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 12. Shoot Summary
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Summary',
                'slug' => 'shoot-summary',
                'description' => 'Summary of completed shoot',
                'category' => 'GENERAL',
                'subject' => 'Shoot Summary - {{shoot_date}}',
                'body_html' => $this->getShootSummaryTemplate(),
                'body_text' => 'Here is a summary of your completed shoot.',
                'variables_json' => ['client_name', 'shoot_date', 'shoot_address', 'photographer_name', 'services_provided', 'photo_count'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 13. Shoot Deleted/Cancelled
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Cancelled',
                'slug' => 'shoot-cancelled',
                'description' => 'Shoot has been cancelled',
                'category' => 'BOOKING',
                'subject' => 'Shoot Cancellation Confirmation',
                'body_html' => $this->getShootCancelledTemplate(),
                'body_text' => 'Your shoot has been cancelled.',
                'variables_json' => ['client_name', 'shoot_date', 'shoot_time', 'cancellation_reason'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 14. Refund Submitted
            [
                'channel' => 'EMAIL',
                'name' => 'Refund Submitted',
                'slug' => 'refund-submitted',
                'description' => 'Refund has been processed',
                'category' => 'PAYMENT',
                'subject' => 'Refund Processed - {{refund_amount}}',
                'body_html' => $this->getRefundSubmittedTemplate(),
                'body_text' => 'Your refund has been processed.',
                'variables_json' => ['client_name', 'refund_amount', 'original_invoice', 'refund_date', 'refund_reason'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
        ];

        foreach ($templates as $template) {
            MessageTemplate::updateOrCreate(
                ['slug' => $template['slug']],
                $template
            );
        }
    }

    private function seedRequiredAutomations(): void
    {
        // Automations will be added later
    }

    // TEMPLATE HTML METHODS
    
    private function getEmailWrapper($content): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f5f5f5; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #1a1a1a; padding: 30px 40px; text-align: center; }
        .logo { font-size: 24px; font-weight: bold; color: #ffffff; letter-spacing: 1px; }
        .content { padding: 40px; }
        .button { display: inline-block; background-color: #000000; color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 20px 0; }
        .info-box { background-color: #f8f8f8; border-left: 4px solid #000000; padding: 20px; margin: 20px 0; }
        .footer { background-color: #f8f8f8; padding: 30px 40px; text-align: center; color: #666666; font-size: 13px; }
        .footer-links { margin: 15px 0; }
        .footer-links a { color: #666666; text-decoration: none; margin: 0 10px; }
        h1 { font-size: 28px; margin: 0 0 20px 0; color: #1a1a1a; }
        p { line-height: 1.6; color: #333333; margin: 15px 0; }
        .detail-row { display: flex; padding: 10px 0; border-bottom: 1px solid #eeeeee; }
        .detail-label { font-weight: 600; width: 140px; color: #666666; }
        .detail-value { color: #1a1a1a; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <div class="logo">REPRO HQ</div>
        </div>
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            <div class="footer-links">
                <a href="#">Help Center</a> | 
                <a href="#">Contact Support</a> | 
                <a href="#">View Online</a>
            </div>
            <p style="margin: 10px 0;">
                <strong>REPRO HQ</strong><br>
                Professional Real Estate Photography<br>
                📞 1-800-REPRO-HQ | 📧 support@reprohq.com
            </p>
            <p style="font-size: 11px; color: #999999;">
                © 2024 REPRO HQ, Inc. All Rights Reserved.<br>
                123 Photography Lane, Studio City, CA 90000, USA
            </p>
            <p style="font-size: 11px; color: #999999;">
                Please contact us if you have any questions.
            </p>
        </div>
    </div>
</body>
</html>';
    }

    private function getAccountCreatedTemplate(): string
    {
        $content = '
            <h1>Welcome to REPRO HQ</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>Your account has been successfully created! We\'re excited to have you join our community of real estate professionals who trust REPRO HQ for stunning property photography.</p>
            
            <div class="info-box">
                <p style="margin: 0;"><strong>Your Account Details:</strong></p>
                <p style="margin: 10px 0 0 0;">Email: {{client_email}}</p>
            </div>
            
            <p>Your account is now active and ready to use. You can:</p>
            <ul>
                <li>Book professional photography shoots</li>
                <li>Track your shoot schedule</li>
                <li>Access and download your photos</li>
                <li>Manage invoices and payments</li>
            </ul>
            
            <center>
                <a href="{{website_url}}" class="button">Start Using REPRO HQ</a>
            </center>
            
            <p>If you have any questions, our support team is here to help!</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootScheduledTemplate(): string
    {
        $content = '
            <h1>Shoot Confirmed ✓</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>Great news! Your photography shoot has been scheduled and confirmed.</p>
            
            <div class="info-box">
                <div class="detail-row">
                    <div class="detail-label">Date:</div>
                    <div class="detail-value">{{shoot_date}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Time:</div>
                    <div class="detail-value">{{shoot_time}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Address:</div>
                    <div class="detail-value">{{shoot_address}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Photographer:</div>
                    <div class="detail-value">{{photographer_name}}</div>
                </div>
            </div>
            
            <p><strong>What to Expect:</strong></p>
            <ul>
                <li>Your photographer will arrive on time and ready to shoot</li>
                <li>Please ensure the property is clean and well-lit</li>
                <li>Photos will be delivered within 24-48 hours</li>
            </ul>
            
            <center>
                <a href="{{shoot_details_link}}" class="button">View Shoot Details</a>
            </center>
            
            <p>Looking forward to capturing amazing photos of your property!</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestedTemplate(): string
    {
        $content = '
            <h1>Shoot Request Received</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>Thank you for requesting a photography shoot with REPRO HQ. We have received your request and are currently checking photographer availability.</p>
            
            <div class="info-box">
                <p style="margin: 0;"><strong>Requested Details:</strong></p>
                <div style="margin-top: 10px;">
                    <div class="detail-row">
                        <div class="detail-label">Preferred Date:</div>
                        <div class="detail-value">{{shoot_date}}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Preferred Time:</div>
                        <div class="detail-value">{{shoot_time}}</div>
                    </div>
                    <div class="detail-row" style="border-bottom: none;">
                        <div class="detail-label">Location:</div>
                        <div class="detail-value">{{shoot_address}}</div>
                    </div>
                </div>
            </div>
            
            <p><strong>Next Steps:</strong></p>
            <ul>
                <li>We\'ll confirm photographer availability within 2-4 hours</li>
                <li>You\'ll receive a confirmation email once approved</li>
                <li>If we need to suggest an alternative time, we\'ll reach out</li>
            </ul>
            
            <p>We\'ll be in touch soon!</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestApprovedTemplate(): string
    {
        $content = '
            <h1>Shoot Request Approved! 🎉</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>Excellent news! We\'ve confirmed photographer availability and your shoot request has been approved.</p>
            
            <div class="info-box">
                <div class="detail-row">
                    <div class="detail-label">Date:</div>
                    <div class="detail-value">{{shoot_date}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Time:</div>
                    <div class="detail-value">{{shoot_time}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Address:</div>
                    <div class="detail-value">{{shoot_address}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Photographer:</div>
                    <div class="detail-value">{{photographer_name}}</div>
                </div>
            </div>
            
            <p>Your shoot is now confirmed and added to your schedule. We\'ll send you a reminder 24 hours before the shoot.</p>
            
            <center>
                <a href="{{shoot_details_link}}" class="button">View Shoot Details</a>
            </center>
            
            <p>We can\'t wait to capture stunning photos of your property!</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestModifiedTemplate(): string
    {
        $content = '
            <h1>Shoot Details Updated & Confirmed</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>We\'ve reviewed your shoot request and made some adjustments to better accommodate your needs. Your shoot is now confirmed with the updated details below.</p>
            
            <div class="info-box">
                <p style="margin: 0 0 10px 0;"><strong>Updated Shoot Details:</strong></p>
                <div class="detail-row">
                    <div class="detail-label">Date:</div>
                    <div class="detail-value">{{shoot_date}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Time:</div>
                    <div class="detail-value">{{shoot_time}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Address:</div>
                    <div class="detail-value">{{shoot_address}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Photographer:</div>
                    <div class="detail-value">{{photographer_name}}</div>
                </div>
            </div>
            
            <p><strong>Changes Made:</strong></p>
            <p>{{changes_made}}</p>
            
            <p>If these updates work for you, no action is needed. If you have any concerns, please contact us immediately.</p>
            
            <center>
                <a href="{{shoot_details_link}}" class="button">View Full Details</a>
            </center>
            
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestDeclinedTemplate(): string
    {
        $content = '
            <h1>Regarding Your Shoot Request</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>Thank you for your interest in booking with REPRO HQ. Unfortunately, we\'re unable to accommodate your shoot request for {{shoot_date}} at this time.</p>
            
            <div class="info-box">
                <p style="margin: 0;"><strong>Reason:</strong></p>
                <p style="margin: 10px 0 0 0;">{{decline_reason}}</p>
            </div>
            
            <p><strong>Alternative Options:</strong></p>
            <ul>
                <li>Request a different date/time that may work better</li>
                <li>Contact our team to discuss flexible scheduling options</li>
                <li>Check our availability calendar for open slots</li>
            </ul>
            
            <center>
                <a href="{{booking_link}}" class="button">View Available Dates</a>
            </center>
            
            <p>We apologize for any inconvenience and hope to serve you soon.</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootReminderTemplate(): string
    {
        $content = '
            <h1>Shoot Reminder 📅</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>This is a friendly reminder that your photography shoot is coming up soon!</p>
            
            <div class="info-box">
                <div class="detail-row">
                    <div class="detail-label">Date:</div>
                    <div class="detail-value">{{shoot_date}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Time:</div>
                    <div class="detail-value">{{shoot_time}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Address:</div>
                    <div class="detail-value">{{shoot_address}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Photographer:</div>
                    <div class="detail-value">{{photographer_name}}</div>
                </div>
            </div>
            
            <p><strong>Pre-Shoot Checklist:</strong></p>
            <ul>
                <li>✓ Clean and declutter the property</li>
                <li>✓ Turn on all lights for optimal lighting</li>
                <li>✓ Open blinds and curtains</li>
                <li>✓ Ensure access to all areas to be photographed</li>
            </ul>
            
            <center>
                <a href="{{shoot_details_link}}" class="button">View Shoot Details</a>
            </center>
            
            <p>Looking forward to capturing beautiful photos of your property!</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootUpdatedTemplate(): string
    {
        $content = '
            <h1>Shoot Details Updated</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>We wanted to let you know that some details of your upcoming shoot have been updated.</p>
            
            <div class="info-box">
                <p style="margin: 0 0 10px 0;"><strong>Current Shoot Details:</strong></p>
                <div class="detail-row">
                    <div class="detail-label">Date:</div>
                    <div class="detail-value">{{shoot_date}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Time:</div>
                    <div class="detail-value">{{shoot_time}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Address:</div>
                    <div class="detail-value">{{shoot_address}}</div>
                </div>
            </div>
            
            <p><strong>What Changed:</strong></p>
            <p>{{changes_made}}</p>
            
            <p>Please review the updated information. If you have any questions or concerns, don\'t hesitate to reach out.</p>
            
            <center>
                <a href="{{shoot_details_link}}" class="button">View Full Details</a>
            </center>
            
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootReadyTemplate(): string
    {
        $content = '
            <h1>Your Photos Are Ready! 📸</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>Fantastic news! Your photos from the {{shoot_date}} shoot have been processed and are now ready for download.</p>
            
            <div class="info-box">
                <div class="detail-row">
                    <div class="detail-label">Shoot Date:</div>
                    <div class="detail-value">{{shoot_date}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Total Photos:</div>
                    <div class="detail-value">{{photo_count}} high-resolution images</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Available Until:</div>
                    <div class="detail-value">{{expiration_date}}</div>
                </div>
            </div>
            
            <p><strong>What\'s Included:</strong></p>
            <ul>
                <li>Professional color-corrected photos</li>
                <li>High-resolution files (300 DPI)</li>
                <li>Optimized for web and print</li>
                <li>Ready to use for MLS listings</li>
            </ul>
            
            <center>
                <a href="{{download_link}}" class="button">Download Your Photos</a>
            </center>
            
            <p>We hope you love the photos! If you need any adjustments or have questions, please let us know.</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getPaymentDueReminderTemplate(): string
    {
        $content = '
            <h1>Payment Reminder</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>This is a friendly reminder that you have an outstanding invoice with REPRO HQ.</p>
            
            <div class="info-box">
                <div class="detail-row">
                    <div class="detail-label">Invoice Number:</div>
                    <div class="detail-value">{{invoice_number}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Amount Due:</div>
                    <div class="detail-value"><strong style="font-size: 18px;">${{amount_due}}</strong></div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Due Date:</div>
                    <div class="detail-value">{{due_date}}</div>
                </div>
            </div>
            
            <p><strong>Payment Methods:</strong></p>
            <ul>
                <li>Pay online via credit card or ACH</li>
                <li>Check or money order</li>
                <li>Wire transfer (for large amounts)</li>
            </ul>
            
            <center>
                <a href="{{payment_link}}" class="button">Pay Now</a>
            </center>
            
            <p>If you\'ve already submitted payment, please disregard this notice. For questions about your invoice, contact our billing team.</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getPaymentThankYouTemplate(): string
    {
        $content = '
            <h1>Payment Received - Thank You! ✓</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>We\'ve successfully received your payment. Thank you for your business!</p>
            
            <div class="info-box">
                <div class="detail-row">
                    <div class="detail-label">Payment Amount:</div>
                    <div class="detail-value"><strong style="font-size: 18px; color: #22c55e;">${{payment_amount}}</strong></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Invoice Number:</div>
                    <div class="detail-value">{{invoice_number}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Payment Date:</div>
                    <div class="detail-value">{{payment_date}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Payment Method:</div>
                    <div class="detail-value">{{payment_method}}</div>
                </div>
            </div>
            
            <p>A receipt has been emailed to you and is available in your account dashboard.</p>
            
            <center>
                <a href="{{invoice_link}}" class="button">View Receipt</a>
            </center>
            
            <p>We appreciate your prompt payment and look forward to working with you again!</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootSummaryTemplate(): string
    {
        $content = '
            <h1>Shoot Summary</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>Here\'s a summary of your completed photography shoot.</p>
            
            <div class="info-box">
                <div class="detail-row">
                    <div class="detail-label">Shoot Date:</div>
                    <div class="detail-value">{{shoot_date}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Property:</div>
                    <div class="detail-value">{{shoot_address}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Photographer:</div>
                    <div class="detail-value">{{photographer_name}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Services:</div>
                    <div class="detail-value">{{services_provided}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Photos Delivered:</div>
                    <div class="detail-value">{{photo_count}} images</div>
                </div>
            </div>
            
            <p><strong>Deliverables:</strong></p>
            <ul>
                <li>✓ High-resolution edited photos</li>
                <li>✓ Professional color correction</li>
                <li>✓ MLS-ready formats</li>
            </ul>
            
            <center>
                <a href="{{shoot_details_link}}" class="button">View Full Summary</a>
            </center>
            
            <p>Thank you for choosing REPRO HQ for your photography needs!</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootCancelledTemplate(): string
    {
        $content = '
            <h1>Shoot Cancellation Confirmed</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>This confirms that your scheduled shoot has been cancelled as requested.</p>
            
            <div class="info-box">
                <p style="margin: 0 0 10px 0;"><strong>Cancelled Shoot Details:</strong></p>
                <div class="detail-row">
                    <div class="detail-label">Date:</div>
                    <div class="detail-value">{{shoot_date}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Time:</div>
                    <div class="detail-value">{{shoot_time}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Reason:</div>
                    <div class="detail-value">{{cancellation_reason}}</div>
                </div>
            </div>
            
            <p>We\'re sorry we couldn\'t complete this shoot. No charges have been applied to your account.</p>
            
            <p><strong>Want to Reschedule?</strong></p>
            <p>We\'d love to work with you in the future! Feel free to book a new shoot whenever you\'re ready.</p>
            
            <center>
                <a href="{{booking_link}}" class="button">Book a New Shoot</a>
            </center>
            
            <p>If you have any questions about this cancellation, please don\'t hesitate to reach out.</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getRefundSubmittedTemplate(): string
    {
        $content = '
            <h1>Refund Processed</h1>
            <p>Hello <strong>{{client_name}}</strong>,</p>
            <p>We\'ve processed a refund to your account as requested.</p>
            
            <div class="info-box">
                <div class="detail-row">
                    <div class="detail-label">Refund Amount:</div>
                    <div class="detail-value"><strong style="font-size: 18px; color: #3b82f6;">${{refund_amount}}</strong></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Original Invoice:</div>
                    <div class="detail-value">{{original_invoice}}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Refund Date:</div>
                    <div class="detail-value">{{refund_date}}</div>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <div class="detail-label">Reason:</div>
                    <div class="detail-value">{{refund_reason}}</div>
                </div>
            </div>
            
            <p><strong>What Happens Next:</strong></p>
            <ul>
                <li>The refund will appear on your original payment method</li>
                <li>Processing time: 5-10 business days</li>
                <li>You\'ll receive a confirmation email once processed</li>
            </ul>
            
            <center>
                <a href="{{refund_details_link}}" class="button">View Refund Details</a>
            </center>
            
            <p>If you have any questions about this refund, please contact our billing team.</p>
            <p>Best regards,<br><strong>The REPRO HQ Team</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }
}

