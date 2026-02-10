<?php

namespace Modules\Contact\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Contact\Models\Contact;
use Modules\Notifications\Services\NotificationService;

class ContactController extends Controller
{
    public function frontContact(Request $request, NotificationService $notifications)
    {
        $user = $request->user('sanctum');
        $validated_data = $request->validate([
            'full_name' => $user ? 'nullable' : 'required|string|min:6',
            'mobile' => $user ? 'nullable' : 'required|string|size:11',
            'email' =>  'nullable|email',
            'subject' => 'required|string|min:6',
            'body' => 'required|string|min:10',
        ]);
        if ($user) {
            $validated_data['full_name'] = $user->full_name;
            $validated_data['mobile'] = $user->mobile;
        }
        $contact = Contact::create($validated_data);
        $notifications->create(
            " ثبت  فرم ارتباط",
            " یک فرم ارتباط با موضوع  {$contact->subject}در سیستم ثبت  شد",
            "notifications_user",
            ['contact' => $contact->id]
        );
        return response()->json(
            [
                'message' => 'پیام شما با موفقیت ثبت شد ',
                'success' => true
            ]
        );
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('contact::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('contact::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('contact::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('contact::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}
}
