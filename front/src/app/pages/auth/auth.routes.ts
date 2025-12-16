import { Routes } from '@angular/router';
import { Login } from './login';
import { Registration } from '@/pages/auth/registration';
import { ConfirmationService } from 'primeng/api';

export default [
  { path: 'login', component: Login, providers: [ConfirmationService] },
  { path: 'registration', component: Registration },
] as Routes;
