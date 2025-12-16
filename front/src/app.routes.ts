import { Routes } from '@angular/router';
import { AppLayout } from '@/layout/component/app.layout';
import { Dashboard } from '@/pages/dashboard/dashboard';
import { Notfound } from '@/pages/notfound/notfound';
import { AuthGuard } from '@/pages/auth/auth.guard';

export const appRoutes: Routes = [
  {
    path: '',
    component: AppLayout,
    canActivate: [AuthGuard],
    children: [
      { path: '', component: Dashboard },
      { path: 'pages', loadChildren: () => import('./app/pages/pages.routes') },
    ],
  },
  { path: 'notfound', component: Notfound },
  { path: 'auth', loadChildren: () => import('./app/pages/auth/auth.routes') },
  { path: '**', redirectTo: '/notfound' },
];
