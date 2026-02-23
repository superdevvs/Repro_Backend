@extends('emails.layouts.master')
@section('title', 'Cancellation Fee Invoice')
@section('extra-styles')
    <style>
        .invoice-details {
            background-color: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .invoice-details p {
            margin: 8px 0;
        }
        .amount {
            font-size: 28px;
            font-weight: bold;
            color: #dc2626;
            text-align: center;
            padding: 20px;
            background-color: #fef2f2;
            border-radius: 8px;
            margin: 20px 0;
        }
        .cancel-footer {
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
    </style>
@endsection
@section('content')
    <h1 style="margin:0 0 16px;font-size:24px;color:#1f2937;">Cancellation Fee Invoice</h1>

    <p>Hello {{ $client->name }},</p>

    <p>A cancellation fee has been applied to your account for the following property:</p>

    <div class="invoice-details">
        <p><strong>Property Address:</strong> {{ $address }}</p>
        <p><strong>Invoice Number:</strong> {{ $invoice->invoice_number }}</p>
        <p><strong>Issue Date:</strong> {{ $invoice->issue_date->format('F j, Y') }}</p>
        <p><strong>Due Date:</strong> {{ $invoice->due_date->format('F j, Y') }}</p>
    </div>

    <div class="amount">
        Amount Due: ${{ number_format($invoice->total, 2) }}
    </div>

    <p>This fee has been applied in accordance with our cancellation policy. Please make payment by the due date to avoid any additional charges.</p>

    <p>If you have any questions about this invoice, please contact our support team.</p>

    <div class="cancel-footer">
        <p>Thank you for your business.</p>
        <p>REPRO HQ</p>
    </div>
@endsection
