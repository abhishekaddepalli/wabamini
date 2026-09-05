import React from "react";
import { AlertTriangle, ShieldAlert, Info, CheckCircle2 } from "lucide-react";
import { Dialog } from "./Dialog";
import { Button } from "../buttons/Button";

export type AlertType = "danger" | "warning" | "info" | "success";

export interface AlertDialogProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  description: React.ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  type?: AlertType;
  isLoading?: boolean;
}

export const AlertDialog: React.FC<AlertDialogProps> = ({
  isOpen,
  onClose,
  onConfirm,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  type = "danger",
  isLoading = false,
}: AlertDialogProps) => {
  const typeConfig: Record<
    AlertType,
    { icon: React.ReactNode; confirmVariant: "destructive" | "primary" }
  > = {
    danger: {
      icon: <ShieldAlert className="h-4 w-4 text-red-600" />,
      confirmVariant: "destructive",
    },
    warning: {
      icon: <AlertTriangle className="h-4 w-4 text-amber-500" />,
      confirmVariant: "primary",
    },
    info: {
      icon: <Info className="h-4 w-4 text-blue-500" />,
      confirmVariant: "primary",
    },
    success: {
      icon: <CheckCircle2 className="h-4 w-4 text-emerald-500" />,
      confirmVariant: "primary",
    },
  };

  const selectedType: AlertType = type && type in typeConfig ? type : "danger";
  const config = typeConfig[selectedType];

  const footerActions = (
    <>
      <Button variant="secondary" onClick={onClose} disabled={isLoading}>
        {cancelLabel}
      </Button>
      <Button
        variant={config.confirmVariant}
        onClick={onConfirm}
        isLoading={isLoading}
      >
        {confirmLabel}
      </Button>
    </>
  );

  return (
    <Dialog
      isOpen={isOpen}
      onClose={onClose}
      title={title}
      icon={config.icon}
      footer={footerActions}
      maxWidth="sm"
    >
      <div className="text-xs text-zinc-600 leading-relaxed font-normal">
        {description}
      </div>
    </Dialog>
  );
};
