import { Component, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { ConfirmationService, MenuItem, MenuItemCommandEvent } from 'primeng/api';
import { AppMenuitem } from './app.menuitem';
import { ConfirmDialog } from 'primeng/confirmdialog';
import { AuthService } from '@/pages/auth/auth.service';

@Component({
  selector: 'app-menu',
  standalone: true,
  imports: [CommonModule, AppMenuitem, RouterModule, ConfirmDialog],
  providers: [ConfirmationService],
  template: `
    <p-confirmdialog [style]="{ width: '450px' }"></p-confirmdialog>
    <ul class="layout-menu">
      <ng-container *ngFor="let item of model; let i = index">
        <li
          app-menuitem
          *ngIf="!item.separator"
          [item]="item"
          [index]="i"
          [root]="true"
        ></li>
        <li
          *ngIf="item.separator"
          class="menu-separator"
        ></li>
      </ng-container>
    </ul>
  `,
})
export class AppMenu implements OnInit {
  model: MenuItem[] = [];
  private confirmationService = inject(ConfirmationService);
  private router = inject(Router);
  private authService = inject(AuthService);

  ngOnInit() {
    this.model = [
      {
        label: 'Pages',
        items: [
          { label: 'Dashboard', icon: 'pi pi-fw pi-home', routerLink: ['/'] },
          {
            label: 'Members',
            icon: 'pi pi-user',
            routerLink: ['/pages/members'],
          },
          {
            label: 'Events',
            icon: 'pi pi-calendar',
            routerLink: ['/pages/events'],
          },
          {
            label: 'Payments',
            icon: 'pi pi-credit-card',
            routerLink: ['/pages/payments'],
          },
          {
            label: 'Logout',
            icon: 'pi pi-fw pi-sign-out',
            command: (event: MenuItemCommandEvent) => {
              this.confirmationService.confirm({
                target: event.originalEvent?.target as HTMLElement,
                message: 'Вы действительно хотите выйти?',
                header: 'Подтверждение',
                icon: 'pi pi-exclamation-triangle',
                accept: () => {
                  this.router.navigate(['auth/login']).then(() => {
                    this.authService.logout();
                  });
                },
              });
            },
          },
        ],
      },
    ];
  }
}
