import { Directive, Input, TemplateRef, ViewContainerRef } from '@angular/core';

interface PermissionContext {
  role: string | undefined;
}

@Directive({
  selector: '[appHasPermission]',
  standalone: true,
})
export class HasPermissionDirective {
  private hasView = false;

  @Input() set appHasPermission(context: PermissionContext) {
    const allowed = this.checkPermission(context);

    if (allowed && !this.hasView) {
      this.viewContainer.createEmbeddedView(this.templateRef);
      this.hasView = true;
    } else if (!allowed && this.hasView) {
      this.viewContainer.clear();
      this.hasView = false;
    }
  }

  constructor(
    private templateRef: TemplateRef<any>,
    private viewContainer: ViewContainerRef,
  ) {}

  private checkPermission({ role }: PermissionContext): boolean {
    // ADMIN — всегда можно
    if (role === 'admin') {
      return true;
    }

    // MEMBER — никогда нельзя
    if (role === 'member') {
      return false;
    }

    return false;
  }
}
