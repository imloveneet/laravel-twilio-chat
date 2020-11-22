<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use App\UserChatChannel;
use Twilio\Rest\Client;

class MessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $users = User::where('id', '<>', $request->user()->id)->get();

        return view('messages.index', compact('users'));
    }

    public function chat(Request $request, $ids)
    {
        $authUser = $request->user();

        $otherUser = User::find(explode('-', $ids)[1]);
        $users = User::where('id', '!=', $authUser->id)->get();
        $chatChannelName = '';

        if(UserChatChannel::where('first_user', $authUser->id)->exists()
            && UserChatChannel::where('second_user', $otherUser->id)->exists())
        {
            $userChatChannel = UserChatChannel::where('first_user', $authUser->id)->where('second_user', $otherUser->id)->first();
            $chatChannelName = $userChatChannel->channel_name;
        } elseif (UserChatChannel::where('first_user', $otherUser->id)->exists()
            && UserChatChannel::where('second_user', $authUser->id)->exists())
        {
            $userChatChannel = UserChatChannel::where('first_user', $otherUser->id)->where('second_user', $authUser->id)->first();
            $chatChannelName = $userChatChannel->channel_name;
        } else {
            $userChatChannel = new UserChatChannel();
            $userChatChannel->first_user = $authUser->id;
            $userChatChannel->second_user = $otherUser->id;
            $userChatChannel->channel_name = str_replace(" ", "", $authUser->name) . '_' . time() . '_' . str_replace(" ", "", $otherUser->name);
            $userChatChannel->save();
            $chatChannelName = $userChatChannel->channel_name;
        }

        $twilio = new Client(env('TWILIO_AUTH_SID'), env('TWILIO_AUTH_TOKEN'));

        // Fetch channel or create a new one if it doesn't exist
        try {
            $channel = $twilio->chat->v2->services(env('TWILIO_SERVICE_SID'))
                ->channels($chatChannelName)
                ->fetch();
        } catch (\Twilio\Exceptions\RestException $e) {
            $channel = $twilio->chat->v2->services(env('TWILIO_SERVICE_SID'))
            ->channels
            ->create([
                'uniqueName' => $chatChannelName,
                'type' => 'private',
            ]);
        }

        // Add first user to the channel
        try {
            $twilio->chat->v2->services(env('TWILIO_SERVICE_SID'))
                ->channels($chatChannelName)
                ->members($authUser->email)
                ->fetch();
        } catch (\Twilio\Exceptions\RestException $e) {
            $member = $twilio->chat->v2->services(env('TWILIO_SERVICE_SID'))
            ->channels($chatChannelName)
            ->members
            ->create($authUser->email);
        }

        // Add second user to the channel
        try {
            $twilio->chat->v2->services(env('TWILIO_SERVICE_SID'))
                ->channels($chatChannelName)
                ->members($otherUser->email)
                ->fetch();
        } catch (\Twilio\Exceptions\RestException $e) {
            $twilio->chat->v2->services(env('TWILIO_SERVICE_SID'))
                ->channels($chatChannelName)
                ->members
                ->create($otherUser->email);
        }

        $authUser->chatChannelName = $chatChannelName;
        return view('messages.chat', compact('users', 'otherUser'));
    }
}
