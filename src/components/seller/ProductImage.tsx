import { useQuery } from "@tanstack/react-query";
import { ImageIcon } from "lucide-react";
import { base64ToBlob } from "@/lib/files";
import { getProductImage } from "@/lib/products.functions";

// Photos are private, so they are fetched through a server function that checks access.
export function ProductImage({ path, alt, className = "" }: { path: string | undefined; alt: string; className?: string }) {
  const { data } = useQuery({
    queryKey: ["product-image", path],
    enabled: Boolean(path),
    staleTime: Infinity,
    gcTime: 10 * 60_000,
    queryFn: async () => {
      const result = await getProductImage({ data: { path: path as string } });
      if (!result.ok) throw new Error(result.error);
      return URL.createObjectURL(base64ToBlob(result.data_base64, result.content_type));
    },
  });
  if (data) return <img src={data} alt={alt} className={`object-cover ${className}`} />;
  return <div className={`grid place-items-center bg-muted text-muted-foreground ${className}`} aria-hidden="true"><ImageIcon className="size-5" /></div>;
}
