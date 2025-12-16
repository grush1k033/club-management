import { inject, Injectable } from '@angular/core';
import { catchError, map, tap } from 'rxjs/operators';
import { of } from 'rxjs';
import { IGeneralResponse, User } from '@/pages/auth/login';
import { HttpClient, HttpContext, HttpContextToken } from '@angular/common/http';
import { BASE_URL } from '../../../constants';

export const SKIP_AUTH = new HttpContextToken(() => false);

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private httpClient = inject(HttpClient);
  // Авторизация
  login(email: string, password: string) {
    return this.httpClient
      .post<
        IGeneralResponse<{
          user: User;
          token: string;
        }>
      >(`http://${BASE_URL}/server/api/auth/login`, {
        email,
        password,
      }, )
      .pipe(
        map(({ data }) => {
            if (!data) return null;
          return data;
        }),
        catchError(() => {
            console.log(1);
            return of(null)
        }),
        tap((data) => {
          if (data?.token) {
            this.setToken(data.token);
          }
        }),
      );
  }

  registration(email: string, firstName: string, lastName: string, password: string) {
    const context = new HttpContext().set(SKIP_AUTH, true);
    return this.httpClient
      .post<
        IGeneralResponse<{
          user: User;
          token: string;
        }>
      >(
        `http://${BASE_URL}/server/api/auth/register`,
        {
          email,
          first_name: firstName,
          last_name: lastName,
          password,
        },
        { context },
      )
      .pipe(
        map(({ data }) => {
          if (!data) return null;
          return data;
        }),
        tap((data) => {
          if (data?.token) {
            this.setToken(data.token);
          }
        }),
        catchError(() => of(null)),
      );
  }

  // Сохранить токен
  setToken(token: string): void {
    localStorage.setItem('token', token);
  }

  // Получить токен
  getToken(): string | null {
    return localStorage.getItem('token');
  }

  // Проверка авторизован ли
  isAuthenticated(): boolean {
    return !!localStorage.getItem('token');
  }

  // Выйти
  logout(): void {
    localStorage.removeItem('token');
  }
}
