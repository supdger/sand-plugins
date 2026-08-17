import type { DefineComponent } from "vue";

interface FormMakeExpose {
  validate(): boolean;
}

declare const FormMake: DefineComponent<Record<string, never>, FormMakeExpose, unknown>;

export default FormMake;
