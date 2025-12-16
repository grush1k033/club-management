import { Routes } from '@angular/router';
import { Events } from '@/pages/events/events';
import { Members } from '@/pages/members/members';
import { Payments } from '@/pages/payments/payments';
import { Profile } from '@/pages/profile/profile';

export default [
  { path: 'members', component: Members },
  { path: 'events', component: Events },
  { path: 'payments', component: Payments },
  { path: 'profile', component: Profile },
  { path: '**', redirectTo: '/notfound' },
] as Routes;
