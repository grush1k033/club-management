import { Injectable } from '@angular/core';
import { HttpInterceptor, HttpRequest, HttpHandler, HttpEvent, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { AuthService, SKIP_AUTH } from '@/pages/auth/auth.service';
import { catchError } from 'rxjs/operators';
import { Router } from '@angular/router';

@Injectable()
export class TokenInterceptor implements HttpInterceptor {
    constructor(
        private auth: AuthService,
        private router: Router
    ) {}

    intercept(req: HttpRequest<any>, next: HttpHandler): Observable<HttpEvent<any>> {
        const token = this.auth.getToken();


        let cloned = req;
        const skipAuth = req.context.get(SKIP_AUTH);

        if(skipAuth) {
            return next.handle(req);
        }

        if (token) {
            cloned = req.clone({
                setHeaders: {
                    'X-Auth-Token': token
                }
            });
        }

        return next.handle(cloned).pipe(
            catchError((error: HttpErrorResponse) => {
                console.log(error);
                if (error.status === 401) {
                    // Разлогиниваем
                    this.auth.logout();

                    // Редирект на логин (если нужно)
                    this.router.navigate(['/auth/login']);
                }

                return throwError(() => error);
            })
        );
    }
}
