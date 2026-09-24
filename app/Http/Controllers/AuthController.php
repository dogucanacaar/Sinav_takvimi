<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Asgari oturum açma.
 *
 * Hazır bir kimlik doğrulama paketi kurulmadı: uygulamanın ihtiyacı
 * e-posta ve parolayla girişten ibaret. Kayıt olma, parola sıfırlama ve
 * e-posta doğrulama bilinçli olarak yok — kullanıcıları fakülte
 * yönetimi tanımlar.
 */
class AuthController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect()->route('schedule');
        }

        // Hiç kullanıcı yoksa "parola hatalı" demek yanıltıcıdır: sorun
        // parolada değil, sistemde henüz kimsenin tanımlı olmamasındadır.
        // Kurulumu yeni yapan biri bu farkı ekranda görmeli.
        return view('auth.login', [
            'noUsers' => ! User::query()->exists(),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'E-posta veya parola hatalı.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('schedule'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
