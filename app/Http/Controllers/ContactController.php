<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;
use Carbon\Carbon;
use \MailchimpTransactional\ApiClient as Transactional;

class ContactController extends Controller
{
    /**
     * Store a new contact form submission
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Log the incoming request for debugging
            Log::info('Contact form submission received', $request->all());

            // Validate the request
            $validator = $this->validateContactForm($request);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $this->getValidationErrorMessage($validator)
                ], 422);
            }

            $data = $validator->validated();

            // Process the contact form data
            $this->processContactSubmission($data);

            // Return success response
            return response()->json([
                'success' => true,
                'message' => 'Contact form submitted successfully',
            ], 200);
        } catch (Exception $e) {
            Log::error('Contact form submission error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Leider konnte die Eingabe nicht gesendet werden! Bitte versuchen Sie es noch einmal!'
            ], 500);
        }
    }

    /**
     * Validate the contact form data
     */
    private function validateContactForm(Request $request)
    {
        return Validator::make($request->all(), [
            'Dein Name' => 'nullable|string|max:255',
            'Deine Telefonnummer' => 'nullable|string|max:20',
            'Dein Unternehmen' => 'required|string|max:255',
            'Mitarbeiteranzahl' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'Catering-Station groß' => 'nullable|string|max:255'
        ], [
            'Dein Unternehmen.required' => 'Field can not be blank',
            'Mitarbeiteranzahl.required' => 'Field can not be blank',
            'email.required' => 'Field can not be blank',
            'email.email' => 'Leider konnte die Eingabe nicht gesendet werden! Bitte versuchen Sie es noch einmal!'
        ]);
    }

    /**
     * Get appropriate error message from validator
     */
    private function getValidationErrorMessage($validator): string
    {
        $errors = $validator->errors();

        // Check for specific error types
        if ($errors->has('email') && $errors->first('email') === 'Leider konnte die Eingabe nicht gesendet werden! Bitte versuchen Sie es noch einmal!') {
            return 'Leider konnte die Eingabe nicht gesendet werden! Bitte versuchen Sie es noch einmal!';
        }

        // Return first error or default message
        return $errors->first() ?: 'Field can not be blank';
    }

    /**
     * Process the contact form submission
     */
    private function processContactSubmission(array $data): void
    {
        // TODO: Implement your business logic here

        // Example: Save to database
        // Contact::create($data);

        // Send notification email using Mailchimp Transactional
        $this->sendContactFormEmail($data);

        // Example: Create user account if needed
        // $this->createUserAccount($data);

        // Example: Send welcome email
        // $this->sendWelcomeEmail($data);

        // For now, just log the data
        Log::info('Contact form processed successfully', $data);
    }

    /**
     * Send contact form email using Mailchimp Transactional
     */
    private function sendContactFormEmail(array $data): void
    {
        try {
            // Set your API key
            $api_key = config('services.mailchimp.MAILCHIMP_TRANSACTIONAL_API_KEY');

            // Create a new MailchimpTransactional client
            $transactional = new Transactional();
            $transactional->setApiKey($api_key);

            // Format submission time
            $submissionTime = Carbon::now('Europe/Berlin')->format('d.m.Y H:i:s');

            // Create email HTML content
            $html = $this->buildContactFormEmailHtml($data, $submissionTime);

            // Create message object
            $message = [
                'html' => $html,
                'subject' => 'Neue Catering-Station Anfrage von ' . $data['Dein Unternehmen'] . ' - ' . $data['Dein Name'],
                'from_email' => $data['email'],
                'from_name' => $data['Dein Name'],
                'to' => [
                    [
                        'email' => env('SHOPIFY_CONTACT_FORM_RECIPIENT_EMAIL', 'anfrage@sushi.catering'),
                        'name' => 'Sushi.Catering Team',
                        'type' => 'to'
                    ]
                ],
                'headers' => [
                    'Reply-To' => $data['email']
                ]
            ];

            // Send the email
            $response = $transactional->messages->send(['message' => $message]);

            // Log success
            Log::info('Contact form email sent successfully via Mailchimp Transactional', [
                'response' => $response,
                'company' => $data['Dein Unternehmen']
            ]);
        } catch (Exception $e) {
            // Log error and re-throw
            Log::error('Failed to send contact form email via Mailchimp Transactional', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Build HTML content for contact form email
     */
    private function buildContactFormEmailHtml(array $data, string $submissionTime): string
    {
        $contactName = $data['Dein Name'] ?? 'Nicht angegeben';
        $contactPhone = $data['Deine Telefonnummer'] ?? 'Nicht angegeben';
        $cateringStation = $data['Catering-Station groß'] ?? 'Standard';

        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>
                    Neue Catering-Station Anfrage
                </h2>
                
                <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <p><strong>Eingangsdatum:</strong> {$submissionTime}</p>
                </div>

                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;'>Unternehmen:</td>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'>{$data['Dein Unternehmen']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;'>Mitarbeiteranzahl:</td>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'>{$data['Mitarbeiteranzahl']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;'>E-Mail:</td>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'><a href='mailto:{$data['email']}'>{$data['email']}</a></td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;'>Kontaktperson:</td>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'>{$contactName}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;'>Telefonnummer:</td>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'>{$contactPhone}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;'>Catering-Station:</td>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'>{$cateringStation}</td>
                    </tr>
                </table>

                <div style='background-color: #e8f5e8; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <p style='margin: 0;'><strong>Hinweis:</strong> Diese Anfrage wurde automatisch über das Kontaktformular auf der Website eingereicht.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
