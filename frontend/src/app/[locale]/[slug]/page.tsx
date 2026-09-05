'use client';

import React, { useEffect, useState, use } from 'react';
import Link from 'next/link';
import DOMPurify from 'isomorphic-dompurify';
import { fetchWithCsrf } from '@/lib/api';
import { LandingNavbar } from '@/components/landing/LandingNavbar';
import { LandingFooter } from '@/components/landing/LandingFooter';
import { ArrowLeft, Calendar, FileText } from 'lucide-react';
import SimpleLoader from '@/components/ui/SimpleLoader';

interface CustomPageData {
  id: number;
  slug: string;
  title: string;
  content: string;
  meta_description: string | null;
  is_published: boolean;
  updated_at: string;
}

export default function PublicCustomPage({ params }: { params: Promise<{ slug: string }> }) {
  const resolvedParams = use(params);
  const slug = resolvedParams.slug;

  const [page, setPage] = useState<CustomPageData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    async function loadPage() {
      setLoading(true);
      try {
        const response = await fetchWithCsrf(`/public/pages/${slug}`);
        if (response.ok) {
          const data: CustomPageData = await response.json();
          setPage(data);
        } else {
          setError(true);
        }
      } catch {
        setError(true);
      } finally {
        setLoading(false);
      }
    }

    loadPage();
  }, [slug]);

  if (loading) {
    return <SimpleLoader message="Loading document..." />;
  }

  if (error || !page) {
    return (
      <div className="min-h-screen bg-white font-sans flex flex-col justify-between selection:bg-[#4AE54A] selection:text-[#0A0A0A]">
        <LandingNavbar />
        <div className="pt-36 pb-20 px-4 text-center max-w-md mx-auto space-y-4">
          <div className="h-12 w-12 rounded-full bg-zinc-50 border border-zinc-200 flex items-center justify-center mx-auto text-zinc-400">
            <FileText className="h-6 w-6" />
          </div>
          <h1 className="text-xl font-bold text-black">Page Not Found</h1>
          <p className="text-xs text-zinc-500 font-medium leading-relaxed">The requested page does not exist or is currently unpublished.</p>
          <Link
            href="/"
            className="inline-flex items-center gap-1.5 px-4 h-8 bg-black text-white text-xs font-bold rounded-lg hover:bg-zinc-800 transition-all shadow-2xs"
          >
            <ArrowLeft className="h-3.5 w-3.5" />
            <span>Return to Home</span>
          </Link>
        </div>
        <LandingFooter />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-white font-sans flex flex-col justify-between selection:bg-[#4AE54A] selection:text-[#0A0A0A]">
      <LandingNavbar />

      {/* Main Single Column Clean Document Container */}
      <main className="pt-28 sm:pt-36 pb-20 px-4 sm:px-6 lg:px-8 max-w-[800px] mx-auto w-full flex-grow text-left">
        
        {/* Back Link */}
        <div className="mb-6">
          <Link
            href="/"
            className="inline-flex items-center gap-1.5 text-xs font-semibold text-zinc-500 hover:text-black transition-colors"
          >
            <ArrowLeft className="h-3.5 w-3.5" />
            <span>Back to Home</span>
          </Link>
        </div>

        {/* Header Title Block */}
        <header className="pb-6 mb-8 border-b border-[#E8E8E6] space-y-2">
          <span className="text-[10px] font-bold uppercase tracking-wider text-zinc-400 block">
            Legal & Documentation
          </span>
          <h1 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
            {page.title}
          </h1>
          <div className="flex items-center gap-1.5 text-xs font-medium text-zinc-400 pt-1">
            <Calendar className="h-3.5 w-3.5" />
            <span>Last updated: {new Date(page.updated_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })}</span>
          </div>
        </header>

        {/* Render Clean Document HTML Content */}
        <article
          className="prose prose-zinc max-w-none text-zinc-700 text-sm sm:text-base leading-relaxed space-y-5 [&_h1]:text-2xl [&_h1]:font-black [&_h1]:text-black [&_h1]:mt-8 [&_h1]:mb-3 [&_h2]:text-lg [&_h2]:font-bold [&_h2]:text-black [&_h2]:mt-6 [&_h2]:mb-2.5 [&_p]:text-zinc-650 [&_p]:leading-relaxed [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1.5 [&_li]:text-zinc-650 [&_strong]:text-black [&_strong]:font-bold"
          dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(page.content) }}
        />

      </main>

      <LandingFooter />
    </div>
  );
}
