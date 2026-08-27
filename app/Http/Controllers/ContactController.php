<?php

namespace App\Http\Controllers;

use App\Mail\contactMail;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function index()
    {
        $contacts = Contact::orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Contact fetched successfully',
            'data' => [
                'contacts' => $contacts,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'sub' => 'nullable|string|max:255',
            'mes' => 'nullable|string',
            'inquiryType' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'eventDate' => 'nullable|string|max:255',
            'quantity' => 'nullable|string|max:255',
            'message' => 'nullable|string',
        ]);

        $contact = Contact::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'sub' => $validated['sub'] ?? null,
            'mes' => $validated['mes'] ?? null,
            'inquiry_type' => $validated['inquiryType'] ?? null,
            'category' => $validated['category'] ?? null,
            'company' => $validated['company'] ?? null,
            'event_date' => $validated['eventDate'] ?? null,
            'quantity' => $validated['quantity'] ?? null,
            'message' => $validated['message'] ?? null,
        ]);


        $subject = $validated['inquiryType'] ?? $validated['sub'] ?? 'New inquiry';
        $body = $validated['message'] ?? $validated['mes'] ?? '';

        if ($contact) {
            Mail::to('contact@momentocardgames.com')
                ->send(new contactMail($validated['name'], $validated['email'], $subject, $body));
        }

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Contact sent successfully',
            'data' => [
                'contact' => $contact,
            ],
        ]);
    }

    public function destroy($id)
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Contact deleted successfully',
        ]);
    }
}