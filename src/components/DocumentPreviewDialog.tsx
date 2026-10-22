import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { downloadVerificationFile } from "@/lib/verification";

type Loaded = { url: string; type: string } | null;

export function DocumentPreviewDialog({ path, title, open, onOpenChange }: { path: string | null; title: string; open: boolean; onOpenChange: (open: boolean) => void }) {
  const [loaded, setLoaded] = useState<Loaded>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!open || !path) return;
    let cancelled = false;
    let objectUrl: string | null = null;
    setLoaded(null);
    setFailed(false);
    downloadVerificationFile(path)
      .then(blob => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setLoaded({ url: objectUrl, type: blob.type });
      })
      .catch(() => { if (!cancelled) setFailed(true); });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [open, path]);

  return <Dialog open={open} onOpenChange={onOpenChange}>
    <DialogContent className="flex h-[90vh] max-w-4xl flex-col gap-3 p-4 sm:p-6">
      <DialogHeader className="pr-8">
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>Private document preview. It is only visible to you and the verification team.</DialogDescription>
      </DialogHeader>
      <div className="min-h-0 flex-1 overflow-auto rounded-md border bg-muted/30">
        {failed ? <p role="alert" className="grid h-full place-items-center p-6 text-center text-destructive">Could not load this document. Close this window and try again.</p>
          : !loaded ? <div className="grid h-full place-items-center text-muted-foreground" role="status"><span className="flex items-center gap-2"><Loader2 className="size-5 animate-spin" />Loading document…</span></div>
          : loaded.type === "application/pdf" ? <iframe title={title} src={loaded.url} className="h-full w-full" />
          : <img src={loaded.url} alt={title} className="mx-auto h-auto max-h-full w-auto max-w-full object-contain" />}
      </div>
    </DialogContent>
  </Dialog>;
}
