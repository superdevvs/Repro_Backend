<?php

return [
    /*
     * Counsel must explicitly activate this release. Keeping the draft in the
     * server registry lets product and legal review the exact bytes whose hash
     * will later be recorded with each acceptance.
     */
    'photographer_documents_active' => (bool) env('PHOTOGRAPHER_LEGAL_DOCUMENTS_ACTIVE', false),

    'roles' => [
        'photographer' => [
            [
                'key' => 'photographer_terms',
                'title' => 'Photographer Terms',
                'version' => 'draft-2026-08-20',
                'effective_date' => null,
                // Draft versions cannot be activated by an environment toggle.
                // Counsel approval must ship a final version and explicitly set
                // this document active in the registry.
                'active' => false,
                'content' => <<<'MARKDOWN'
# Photographer Terms

These terms describe the working relationship between R/E Pro Photos and photographers who accept assignments through the platform.

## Assignment scope
Each assignment is limited to the service items explicitly assigned to you. Confirm the scope, timing, property access instructions, and deliverables before travel. Do not perform or represent that you are responsible for a peer photographer's assigned services.

## Scheduling and property access
Arrive at the scheduled time, follow authorized access instructions, protect keys and access codes, and promptly report conflicts, unsafe conditions, or an inability to complete the assignment.

## Conduct and safety
Act professionally and respectfully with clients, occupants, staff, and the public. You may stop work when a site or requested activity is unsafe and must promptly notify R/E Pro Photos.

## Equipment and quality
Maintain suitable, safe, and lawful equipment and follow the current production and upload standards for the assigned service.

## Compensation and taxes
Compensation is based on accepted assignments and the applicable rate shown by R/E Pro Photos. Unless a written employment agreement says otherwise, you are responsible for your own taxes, permits, and business expenses.

## Media and intellectual property
Upload assignment media only through approved systems. You grant R/E Pro Photos the rights needed to process, edit, deliver, archive, and license the work for the applicable client order. Do not separately publish client-property media or confidential assignment details without authorization.

## Confidentiality and privacy
Protect client, property, access, schedule, and account information. Use it only to complete the assignment and do not disclose it to unassigned people.

## Insurance
Maintain any insurance and licensing required by law or communicated for an assignment, including coverage appropriate to your equipment, vehicle use, and on-site work.

## Account security
Keep credentials and verification methods private, use your own account, and report suspected compromise immediately.

## Suspension and termination
R/E Pro Photos may limit assignments or suspend access for safety, security, legal, quality, or policy concerns. Either party may end the relationship subject to completing or properly handing off accepted assignments and preserving confidentiality.
MARKDOWN,
            ],
            [
                'key' => 'photographer_privacy',
                'title' => 'Photographer Privacy Notice',
                'version' => 'draft-2026-08-20',
                'effective_date' => null,
                'active' => false,
                'content' => <<<'MARKDOWN'
# Photographer Privacy Notice

This notice explains how R/E Pro Photos handles information associated with photographer accounts and assignments.

## Information we collect
We process account and identity details, contact and verification data, equipment information, availability, approved service-area and location information, assignment and client-property data, upload and file metadata, support communications, payment and tax records, and security or activity logs.

## How we use information
We use this information to authenticate accounts, match and administer assignments, coordinate scheduling and property access, process and deliver media, calculate compensation, prevent fraud and abuse, provide support, meet legal obligations, and improve platform reliability.

## Assignment and location sharing
Authorized staff and participants may receive the assignment details needed for their role. Public booking availability must not expose a photographer's home street address, postal code, or prior-shoot location.

## Processors and service providers
We may use vetted hosting, storage, communications, payment, security, mapping, analytics, and production vendors to operate the service. They may process only the information needed for their contracted function.

## Retention
We retain records for operational, accounting, security, dispute, and legal needs. Retention varies by record type; information is deleted or de-identified when no longer reasonably required, subject to lawful preservation duties.

## Security
We use administrative and technical controls intended to protect information, but no system is risk free. Report suspected account or data compromise immediately.

## Your choices and rights
Depending on applicable law, you may request access, correction, deletion, restriction, portability, or information about disclosures. Some records must be retained for legal, security, or transaction purposes.

## Contact
Contact R/E Pro Photos through the support contact shown in the dashboard for privacy questions or requests. SMS consent is separate, optional, and is not created by accepting this notice.
MARKDOWN,
            ],
        ],
    ],
];
